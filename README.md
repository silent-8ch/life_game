# Paul 3D sprite pipeline

Generate the rigged model and preview renders:

```sh
blender --background --python birthday/sprites/doom/paul-3d/build_paul.py
```

Outputs:

- `paul-lowpoly.blend` — editable rigged Blender model
- `renders/paul-front.png`
- `renders/paul-three-quarter.png`
- `renders/paul-side.png`
- `paul-3d-cardinal-4step.png` — deterministic guide: front, right, back, left
- `paul-3d-diagonal-4step.png` — deterministic guide: front-right, back-right, back-left, front-left
- `paul-illustrated-cardinal-4step.png` — final hand-drawn cardinal atlas
- `paul-illustrated-diagonal-4step.png` — final hand-drawn diagonal atlas
- `textures/paul-turnaround-reference.png` — AI-assisted texture reference derived from the source photos
- `mocap/cmu-07-01-walk.bvh` — standard CMU Graphics Lab walk motion (`07_01`)

The Blender script owns geometry, proportions, rigging, mocap retargeting, lighting, camera alignment, and rendering. Image generation is limited to texture reference work. Walk motion comes from the public CMU Graphics Lab Motion Capture Database.
