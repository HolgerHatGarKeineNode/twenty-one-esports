/**
 * The centre guard: every light the broadcast throws (halos, rays, flares, shock rings, embers, the bloom itself) is
 * cut to zero inside the free 16:9 centre (SLOTS.centre, 400..1520 x 225..855 of the 1920x1080 frame), measured on
 * the screen (gl_FragCoord), so perspective, blur spill and flying embers cannot leak into the stream. Plates and
 * text are laid out outside the centre and do not need it.
 *
 * The uniforms are shared objects: the stage writes the drawing-buffer size once per resize, the stinger switches the
 * guard off while it covers the cut (it is the one element allowed over the centre).
 */

export const GUARD = {
    guardOn: { value: 1 },
    // A full-screen scene (break, bracket: plan P5, P6) has no stream under it: the guard stays off for good.
    fullScreen: false,
    screen: { value: { x: 1920, y: 1080 } },
};

/** GLSL: guard() is 1 outside the centre and fades to 0 within the centre's outer 6 px. */
export const GUARD_GLSL = `uniform float guardOn; uniform vec2 screen;
    float guard() {
        vec2 p = vec2(gl_FragCoord.x / screen.x * 1920.0, (1.0 - gl_FragCoord.y / screen.y) * 1080.0);
        vec2 d = max(vec2(400.0, 225.0) - p, p - vec2(1520.0, 855.0));
        return mix(1.0, smoothstep(-6.0, 0.0, max(d.x, d.y)), guardOn);
    }`;
