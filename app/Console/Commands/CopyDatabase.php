<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Copies every row from one database to another, table for table.
 *
 * Written for the move from SQLite to MySQL, and written as a command rather
 * than done by hand because **the copy that matters is not this one**. The demo
 * holds four levels drawn by children that exist nowhere else — no repo, no
 * other machine — and pointing that instance at an empty MySQL loses them. The
 * ruling on the board is that its data moves first and is verified on the far
 * side before anything switches, and a procedure somebody follows from memory
 * at that moment is the wrong shape of thing.
 *
 * ## What it does not decide
 *
 * It does not create the schema. Run `migrate` against the target first; this
 * fills tables that are already there, and a table the target does not have is
 * reported and skipped rather than guessed at. Keeping the two apart means the
 * schema arrives by the same migrations as everywhere else, and this only ever
 * moves rows.
 *
 * `migrations` itself is never copied, for that reason: the target's own row
 * per migration is the true account of what has been run against it, and
 * overwriting it with the source's would be a lie that only shows up at the
 * next deploy.
 *
 * ## Foreign keys
 *
 * Checks are off for the duration. Copying in dependency order would work until
 * the first circular reference and then quietly not, and the order would be
 * another thing to keep in step with the schema. The keys are checked again at
 * the end by being switched back on — a copy that broke one fails there.
 */
class CopyDatabase extends Command
{
    protected $signature = 'db:copy
        {--from=sqlite : The connection to read from}
        {--to=mysql : The connection to write to}
        {--from-database= : Override the source connection\'s database (a file path, for sqlite)}
        {--to-database= : Override the target connection\'s database}
        {--pretend : Say what would be copied and write nothing}';

    protected $description = 'Copy every row from one database connection to another';

    /** Never copied: the target keeps its own account of what it has run. */
    private const NEVER = ['migrations'];

    /** Rows read and written at a time, so a big table does not become a big array. */
    private const CHUNK = 500;

    public function handle(): int
    {
        // Both connections read `DB_DATABASE`, so on the one run that matters —
        // the run that copies the old database into the new one — they would
        // otherwise both point at whichever of the two `.env` names. Naming the
        // source explicitly is what lets the copy happen before the switch,
        // which is the order the levels drawn by children depend on.
        $this->pointAt($this->option('from'), $this->option('from-database'));
        $this->pointAt($this->option('to'), $this->option('to-database'));

        $from = DB::connection($this->option('from'));
        $to = DB::connection($this->option('to'));

        if ($from->getName() === $to->getName()) {
            $this->error('The source and the target are the same connection.');

            return self::FAILURE;
        }

        $tables = $this->tablesIn($from);
        $missing = array_values(array_diff($tables, $this->tablesIn($to)));

        foreach ($missing as $table) {
            $this->warn("Skipping [{$table}]: the target has no such table. Has it been migrated?");
        }

        $copying = array_values(array_diff($tables, $missing));

        if ($this->option('pretend')) {
            return $this->report($from, $to, $copying, counted: false);
        }

        $this->withoutForeignKeys($to, function () use ($from, $to, $copying): void {
            foreach ($copying as $table) {
                $this->copy($from, $to, $table);
            }
        });

        return $this->report($from, $to, $copying, counted: true);
    }

    /**
     * Points a connection at a particular database, if one was named.
     *
     * Set before the connection is resolved, so it is what the connection is
     * built with rather than something changed underneath it.
     */
    private function pointAt(string $connection, ?string $database): void
    {
        if ($database === null || $database === '') {
            return;
        }

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);
    }

    /**
     * Every table on a connection, without the ones that are never copied.
     *
     * @return list<string>
     */
    private function tablesIn(ConnectionInterface $connection): array
    {
        /** @var Connection $connection */
        $names = array_column($connection->getSchemaBuilder()->getTables(), 'name');

        sort($names);

        return array_values(array_diff($names, self::NEVER));
    }

    /**
     * One table's rows, in chunks, over whatever the target already held.
     */
    private function copy(ConnectionInterface $from, ConnectionInterface $to, string $table): void
    {
        $to->table($table)->truncate();

        $moved = 0;

        $from->table($table)->orderBy($this->orderFor($from, $table))->chunk(
            self::CHUNK,
            function ($rows) use ($to, $table, &$moved): void {
                $written = array_map(
                    static fn (object $row): array => (array) $row,
                    $rows->all(),
                );

                $to->table($table)->insert($written);
                $moved += count($written);
            },
        );

        $this->line(sprintf('  %-28s %6d', $table, $moved));
    }

    /**
     * Something stable to page a table by.
     *
     * `chunk` needs an order or it may hand back the same rows twice, and not
     * every table here has an `id` — the pivots are keyed by their two columns.
     * The first column is always a real one and is enough: the order does not
     * matter, only that it does not change between pages.
     */
    private function orderFor(ConnectionInterface $from, string $table): string
    {
        /** @var Connection $from */
        $columns = $from->getSchemaBuilder()->getColumnListing($table);

        return in_array('id', $columns, strict: true) ? 'id' : $columns[0];
    }

    /**
     * Runs something with the target's foreign keys switched off, and switches
     * them back on however it goes.
     */
    private function withoutForeignKeys(ConnectionInterface $to, callable $work): void
    {
        /** @var Connection $to */
        $to->getSchemaBuilder()->withoutForeignKeyConstraints(function () use ($work): void {
            $work();
        });
    }

    /**
     * What is on each side, table for table, so the copy can be believed.
     *
     * Counted from the databases rather than from what the copy thought it
     * wrote, because those are different claims and only one of them is
     * evidence.
     *
     * @param  list<string>  $tables
     */
    private function report(
        ConnectionInterface $from,
        ConnectionInterface $to,
        array $tables,
        bool $counted,
    ): int {
        $rows = [];
        $wrong = 0;

        foreach ($tables as $table) {
            $here = $from->table($table)->count();
            $there = $counted ? $to->table($table)->count() : 0;
            $same = $here === $there;

            if ($counted && ! $same) {
                $wrong++;
            }

            $rows[] = [
                $table,
                $here,
                $counted ? $there : '—',
                $counted ? ($same ? 'ok' : 'DIFFERS') : 'would copy',
            ];
        }

        $this->newLine();
        $this->table(['table', $this->option('from'), $this->option('to'), ''], $rows);

        if ($wrong > 0) {
            $this->error("{$wrong} table(s) did not come across whole.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
