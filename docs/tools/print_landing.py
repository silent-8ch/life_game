#!/Users/phlusko/projects/thermal_printer_hello_world/.venv/bin/python
"""Print a receipt on the thermal printer when something reaches production.

    python3 docs/tools/print_landing.py <commit>
    python3 docs/tools/print_landing.py <from>..<to>     # one receipt per commit
    python3 docs/tools/print_landing.py --dry-run <commit>

Paul has an Epson TM-m30 on the network and wants a slip every time something
is playable on the demo. Production runs this after a deploy, for the commits
that deploy carried. One receipt per commit, title and full message, so it
reads as the author wrote it.

Uses the printer project at ~/projects/thermal_printer_hello_world as a
library rather than copying its ESC/POS. That project's own venv has the
dependencies, and the shebang points at it — so run it as `./docs/tools/...`
or with that venv's python explicitly. An earlier version re-exec'd itself
into the venv with os.execv; that hung under a shell wrapper and was the one
thing the library's own direct call did not do.

Merge commits are skipped — their messages are git's, not anybody's, and a
deploy that carried six commits should print the six things that happened and
not the merge that stitched them.
"""

import os
import subprocess
import sys
import textwrap

PRINTER_PROJECT = os.path.expanduser('~/projects/thermal_printer_hello_world')

#: Characters across the slip at the printer's default font on 576-dot paper.
COLUMNS = 48

#: Board commits are planning's notes to itself. A kid cannot play a board
#: entry, so it does not get a receipt.
SKIP_PREFIXES = ('Board:',)


def commits_in(spec):
    """Non-merge commits for one hash or a range, oldest first."""
    rev_list = ['git', 'rev-list', '--no-merges', '--reverse']
    rev_list += [spec] if '..' in spec else ['-1', spec]
    out = subprocess.run(rev_list, capture_output=True, text=True, check=True).stdout
    return [line for line in out.split('\n') if line]


def message_of(sha):
    """Subject, body, author and time, straight from git."""
    fmt = '%h%x00%s%x00%b%x00%an%x00%ci'
    out = subprocess.run(['git', 'show', '-s', f'--format={fmt}', sha],
                         capture_output=True, text=True, check=True).stdout
    short, subject, body, author, when = out.split('\x00')
    return short, subject.strip(), body.strip(), author.strip(), when.strip()[:16]


def wrap(paragraph):
    """Git body paragraphs are hard-wrapped at 72; the slip is 48. Reflow."""
    return textwrap.wrap(' '.join(paragraph.split()), COLUMNS) or ['']


def lines_for(short, subject, body, author, when):
    """The slip, as lines. Title set off, body reflowed, footer with the hash."""
    lines = ['=' * COLUMNS]
    lines += textwrap.wrap(subject.upper(), COLUMNS)
    lines.append('=' * COLUMNS)
    lines.append('')

    for paragraph in body.split('\n\n'):
        if paragraph.strip():
            lines += wrap(paragraph)
            lines.append('')

    lines.append('-' * COLUMNS)
    lines.append(f'{author}  {when}')
    lines.append(f'{short}  on production')
    return lines


def print_lines(lines, dry_run):
    if dry_run:
        print('\n'.join(lines))
        print()
        return

    sys.path.insert(0, PRINTER_PROJECT)
    from thermal.printer import build_text_job
    from thermal.transport import make_printer
    from thermal import escpos

    # build_text_job centres every line; a body reads better left-aligned
    # with only the title centred, so assemble the job by hand from the
    # same primitives rather than fighting the helper.
    job = escpos.initialize()
    job += escpos.align(escpos.ALIGN_LEFT)
    for line in lines:
        job += escpos.text(line + '\n')
    job += escpos.feed(4)
    job += escpos.cut()

    with make_printer() as printer:
        printer.write(job)


def main(argv):
    dry_run = '--dry-run' in argv
    args = [a for a in argv[1:] if a != '--dry-run']

    if not args:
        print('usage: print_landing.py [--dry-run] <commit> | <from>..<to>', file=sys.stderr)
        return 2

    printed = 0
    for sha in commits_in(args[0]):
        short, subject, body, author, when = message_of(sha)

        if subject.startswith(SKIP_PREFIXES):
            continue

        print_lines(lines_for(short, subject, body, author, when), dry_run)
        printed += 1

    if not dry_run:
        print(f'{printed} receipt(s) printed')

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
