(function() {
    const canvas = document.getElementById('whm-blizzard');
    const loading = document.getElementById('whm-loading');
    const body = document.body;

    const prefersReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!canvas || prefersReduce) {
        if (body) {
            body.classList.add('whm-reduced');
        }
        if (loading) {
            loading.classList.add('hidden');
        }
        return;
    }

    const gl = canvas.getContext('webgl', {
        alpha: false,
        antialias: false,
        depth: false,
        stencil: false,
        powerPreference: 'high-performance'
    });

    if (!gl) {
        if (body) {
            body.classList.add('whm-reduced');
        }
        if (loading) {
            loading.classList.add('hidden');
        }
        return;
    }

    const vertexSource = `
        attribute vec2 a_position;
        varying vec2 v_uv;
        void main() {
            v_uv = (a_position + 1.0) * 0.5;
            gl_Position = vec4(a_position, 0.0, 1.0);
        }
    `;

    const fragmentSource = `
        precision highp float;
        varying vec2 v_uv;
        uniform vec2 u_resolution;
        uniform float u_time;
        uniform float u_seed;
        uniform vec2 u_view; // yaw (x), pitch (y)
        uniform float u_quality;

        const float PI = 3.14159265359;

        float hash(vec2 p) {
            p = fract(p * 0.3183099 + u_seed);
            p *= 17.0;
            return fract(p.x * p.y * (p.x + p.y));
        }

        float noise(vec2 p) {
            vec2 i = floor(p);
            vec2 f = fract(p);
            float a = hash(i);
            float b = hash(i + vec2(1.0, 0.0));
            float c = hash(i + vec2(0.0, 1.0));
            float d = hash(i + vec2(1.0, 1.0));
            vec2 u = f * f * (3.0 - 2.0 * f);
            return mix(a, b, u.x) + (c - a) * u.y * (1.0 - u.x) + (d - b) * u.x * u.y;
        }

        float fbm(vec2 p) {
            float f = 0.0;
            float w = 0.5;
            for (int i = 0; i < 5; i++) {
                f += w * noise(p);
                p *= 2.07;
                w *= 0.5;
            }
            return f;
        }

        mat2 rot(float a) {
            float s = sin(a); float c = cos(a);
            return mat2(c, -s, s, c);
        }

        float gust(float t) {
            float n = fbm(vec2(t * 0.06, t * 0.04));
            return smoothstep(0.35, 0.8, n);
        }

        struct WaveSample {
            float height;
            vec3 grad;
            float foam;
        };

        WaveSample gerstner(vec2 dir, float amp, float wl, float steep, float speed, vec2 p) {
            float k = 2.0 * PI / wl;
            float phase = dot(dir, p) * k + u_time * speed;
            float s = sin(phase);
            float c = cos(phase);
            float disp = amp * s;
            float df = amp * steep * k * c;
            vec3 g = vec3(dir.x * df, 0.0, dir.y * df);
            float crest = smoothstep(0.65, 1.05, abs(df));
            return WaveSample(disp, g, crest * max(0.0, s));
        }

        WaveSample sampleWaves(vec2 pos) {
            vec3 grad = vec3(0.0);
            float h = 0.0;
            float foam = 0.0;

            float swellPulse = 0.7 + gust(u_time) * 0.9;
            vec2 heroDir = normalize(vec2(0.9, 0.35));
            WaveSample hero = gerstner(heroDir, 1.2 * swellPulse, 8.5, 1.05, 1.25, pos);
            h += hero.height; grad += hero.grad; foam += hero.foam;

            vec2 hero2Dir = normalize(vec2(0.7, 0.55));
            WaveSample hero2 = gerstner(hero2Dir, 0.7 * swellPulse, 11.0, 0.75, 0.95, pos * 0.92 + vec2(0.5, -0.2));
            h += hero2.height; grad += hero2.grad; foam += hero2.foam * 0.6;

            WaveSample mid1 = gerstner(normalize(vec2(1.0, 0.05)), 0.35, 4.6, 0.9, 1.7, pos * 1.3);
            h += mid1.height; grad += mid1.grad; foam += mid1.foam * 0.5;

            WaveSample mid2 = gerstner(normalize(vec2(0.2, 1.0)), 0.28, 5.5, 0.8, 1.45, pos * 1.15 + 1.3);
            h += mid2.height; grad += mid2.grad; foam += mid2.foam * 0.4;

            WaveSample chop = gerstner(normalize(vec2(1.0, 0.6)), 0.08, 1.6, 0.9, 3.2, pos * 2.4 + vec2(0.1, 0.8));
            h += chop.height; grad += chop.grad;

            return WaveSample(h, grad, foam);
        }

        float heightAt(vec2 pos, out vec3 normal, out float foamMask) {
            WaveSample w = sampleWaves(pos);
            normal = normalize(vec3(-w.grad.x, 1.0, -w.grad.z));
            float slope = length(vec2(w.grad.x, w.grad.z));
            float noiseFoam = smoothstep(0.45, 0.9, fbm(pos * 0.5 + u_time * 0.4));
            foamMask = clamp(w.foam * 0.9 + slope * 0.12 + noiseFoam * 0.35, 0.0, 1.0);
            return w.height;
        }

        vec3 skyColor(vec3 rd) {
            float h = max(rd.y, 0.0);
            vec3 horizon = vec3(0.08, 0.14, 0.2);
            vec3 zenith = vec3(0.03, 0.07, 0.12);
            vec3 sky = mix(horizon, zenith, smoothstep(0.0, 0.8, h));
            vec3 sunDir = normalize(vec3(0.6, 0.72, 0.25));
            float sun = pow(max(dot(rd, sunDir), 0.0), 64.0);
            return sky + sun * vec3(0.9, 0.85, 0.75) * 0.8;
        }

        vec3 renderOcean(vec3 ro, vec3 rd) {
            if (rd.y >= -0.02) {
                vec3 sk = skyColor(rd);
                return sk;
            }

            float t = max(0.0, (0.0 - ro.y) / rd.y);
            float h; vec3 n; float foam;
            for (int i = 0; i < 7; i++) {
                vec3 pos = ro + rd * t;
                h = heightAt(pos.xz, n, foam);
                float diff = (pos.y - h);
                t -= diff / rd.y;
            }

            vec3 pos = ro + rd * t;
            h = heightAt(pos.xz, n, foam);

            float distFog = 1.0 - exp(-t * 0.16);
            vec3 fogColor = vec3(0.28, 0.4, 0.55);

            vec3 sunDir = normalize(vec3(0.6, 0.72, 0.25));
            float fresnel = pow(1.0 - clamp(dot(n, -rd), 0.0, 1.0), 3.0) * 0.65 + 0.08;
            vec3 refl = skyColor(reflect(rd, n));
            vec3 deep = vec3(0.01, 0.04, 0.08);
            vec3 shallow = vec3(0.05, 0.12, 0.18);
            float depthTone = clamp(1.0 - (h + 1.5) * 0.35, 0.0, 1.0);
            vec3 base = mix(shallow, deep, depthTone);

            float spec = pow(max(dot(reflect(rd, n), sunDir), 0.0), 140.0) * 1.4;
            float foamDetail = smoothstep(0.55, 1.05, foam) * 0.9;
            float foamJitter = smoothstep(0.4, 0.95, noise(pos.xz * 2.8 + u_time * 0.6));
            float whitecap = clamp(foamDetail + foamJitter * 0.7, 0.0, 1.0);
            vec3 foamColor = mix(vec3(0.86, 0.9, 0.95), vec3(1.0), 0.4);

            vec3 color = base;
            color = mix(color, refl, fresnel);
            color += spec * vec3(1.0, 0.95, 0.85);
            color = mix(color, foamColor, whitecap * 0.9);

            color = mix(color, fogColor, distFog * 0.55);
            return color;
        }

        void main() {
            vec2 uv = v_uv;
            vec2 p = (uv - 0.5) * 2.0;
            p.x *= u_resolution.x / u_resolution.y;

            vec2 jitter = vec2(hash(gl_FragCoord.xy + 1.3) - 0.5, hash(gl_FragCoord.xy + 5.7) - 0.5) / u_quality;
            p += jitter;

            float yaw = u_view.x;
            float pitch = u_view.y;
            vec3 forward = normalize(vec3(cos(pitch) * sin(yaw), sin(pitch), cos(pitch) * cos(yaw)));
            vec3 right = normalize(vec3(cos(yaw), 0.0, -sin(yaw)));
            vec3 up = normalize(cross(right, forward));

            float fov = 1.2;
            vec3 rd = normalize(forward + p.x * right * fov + p.y * up * fov);

            vec3 ro = vec3(0.0, 1.4, -3.0);
            ro += forward * 0.2;

            vec3 col = renderOcean(ro, rd);

            float vig = smoothstep(0.95, 0.4, length(uv - 0.5));
            col *= vig;

            float dither = (hash(gl_FragCoord.xy / u_resolution.xy + u_seed) - 0.5) / 255.0;
            col += dither;

            gl_FragColor = vec4(pow(col, vec3(0.95)), 1.0);
        }
    `;

    function compileShader(type, source) {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            console.warn('Shader compile error:', gl.getShaderInfoLog(shader));
            gl.deleteShader(shader);
            return null;
        }
        return shader;
    }

    const vert = compileShader(gl.VERTEX_SHADER, vertexSource);
    const frag = compileShader(gl.FRAGMENT_SHADER, fragmentSource);
    if (!vert || !frag) {
        if (body) {
            body.classList.add('whm-reduced');
        }
        if (loading) {
            loading.classList.add('hidden');
        }
        return;
    }

    const program = gl.createProgram();
    gl.attachShader(program, vert);
    gl.attachShader(program, frag);
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
        console.warn('Program link error:', gl.getProgramInfoLog(program));
        if (body) {
            body.classList.add('whm-reduced');
        }
        if (loading) {
            loading.classList.add('hidden');
        }
        return;
    }

    gl.useProgram(program);

    const positionLoc = gl.getAttribLocation(program, 'a_position');
    const timeLoc = gl.getUniformLocation(program, 'u_time');
    const resolutionLoc = gl.getUniformLocation(program, 'u_resolution');
    const seedLoc = gl.getUniformLocation(program, 'u_seed');
    const viewLoc = gl.getUniformLocation(program, 'u_view');
    const qualityLoc = gl.getUniformLocation(program, 'u_quality');

    const buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([
        -1, -1,
         1, -1,
        -1,  1,
        -1,  1,
         1, -1,
         1,  1,
    ]), gl.STATIC_DRAW);

    gl.enableVertexAttribArray(positionLoc);
    gl.vertexAttribPointer(positionLoc, 2, gl.FLOAT, false, 0, 0);

    let start = performance.now();
    let width = 0;
    let height = 0;
    const seed = Math.random() * 100.0;
    const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent || '');
    const baseQuality = isMobile ? 128.0 : 256.0;

    let yaw = 0.0;
    let pitch = -0.25;
    let targetYaw = yaw;
    let targetPitch = pitch;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    function clampPitch(v) {
        return Math.max(-0.7, Math.min(0.2, v));
    }

    function resize() {
        const dpr = Math.min(window.devicePixelRatio || 1, 2.0);
        const w = Math.floor(gl.canvas.clientWidth * dpr);
        const h = Math.floor(gl.canvas.clientHeight * dpr);
        if (w !== width || h !== height) {
            width = w;
            height = h;
            gl.canvas.width = width;
            gl.canvas.height = height;
            gl.viewport(0, 0, width, height);
        }
    }

    function onPointerMove(e) {
        if (!dragging) return;
        const dx = e.clientX - lastX;
        const dy = e.clientY - lastY;
        lastX = e.clientX;
        lastY = e.clientY;
        targetYaw += dx * 0.004;
        targetPitch = clampPitch(targetPitch + dy * 0.004);
    }

    function onPointerDown(e) {
        dragging = true;
        lastX = e.clientX;
        lastY = e.clientY;
        if (canvas.setPointerCapture) {
            canvas.setPointerCapture(e.pointerId);
        }
    }

    function onPointerUp(e) {
        dragging = false;
        if (canvas.releasePointerCapture) {
            canvas.releasePointerCapture(e.pointerId);
        }
    }

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointerleave', onPointerUp);

    function render(now) {
        resize();
        const t = (now - start) * 0.001;

        yaw += (targetYaw - yaw) * 0.08;
        pitch += (targetPitch - pitch) * 0.08;

        gl.uniform1f(timeLoc, t);
        gl.uniform2f(resolutionLoc, gl.canvas.width, gl.canvas.height);
        gl.uniform1f(seedLoc, seed);
        gl.uniform2f(viewLoc, yaw, pitch);
        gl.uniform1f(qualityLoc, baseQuality);

        gl.drawArrays(gl.TRIANGLES, 0, 6);

        if (loading) {
            loading.classList.add('hidden');
        }
        requestAnimationFrame(render);
    }

    function init() {
        gl.disable(gl.DEPTH_TEST);
        gl.disable(gl.CULL_FACE);
        gl.clearColor(0, 0, 0, 1);
        requestAnimationFrame(render);
    }

    init();
})();
