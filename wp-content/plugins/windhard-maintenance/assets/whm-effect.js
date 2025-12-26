console.log('WHM_EFFECT_VERSION=PR-2024-06-20-01');
window.__WHM_EFFECT_VERSION = 'PR-2024-06-20-01';
window.__WHM_EFFECT_EXPECTED = window.__WHM_EFFECT_VERSION;

(function() {
    const canvas = document.getElementById('whm-effect');
    const loading = document.getElementById('whm-loading');
    const errorNode = document.getElementById('whm-effect-error');
    const watermark = document.getElementById('whm-effect-watermark');
    const body = document.body;

    if (watermark) {
        watermark.textContent = 'EFFECT_EXPECTED: ' + window.__WHM_EFFECT_VERSION;
    }

    function showError(message) {
        if (errorNode) {
            errorNode.textContent = message;
            errorNode.classList.remove('hidden');
        }
        if (loading) {
            loading.textContent = message;
            loading.classList.remove('hidden');
        }
        window.__WHM_EFFECT_INIT_FAILED = true;
    }

    if (!canvas) {
        showError('EFFECT INIT FAILED');
        return;
    }

    const prefersReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduce) {
        body.classList.add('whm-reduced');
        if (loading) {
            loading.classList.add('hidden');
        }
        return;
    }

    const gl = canvas.getContext('webgl', { antialias: false, preserveDrawingBuffer: false });
    if (!gl) {
        showError('EFFECT INIT FAILED');
        return;
    }

    const vertexSrc = `
        attribute vec2 a_position;
        void main() {
            gl_Position = vec4(a_position, 0.0, 1.0);
        }
    `;

    const fragmentSrc = `
        precision highp float;
        uniform vec2 u_resolution;
        uniform float u_time;
        uniform vec2 u_pointer;

        float hash(vec2 p) {
            p = fract(p * vec2(123.34, 345.45));
            p += dot(p, p + 34.345);
            return fract(p.x * p.y);
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
            float v = 0.0;
            float a = 0.5;
            for (int i = 0; i < 5; i++) {
                v += a * noise(p);
                p *= 2.0;
                a *= 0.55;
            }
            return v;
        }

        // Wave packed as vec4: dir.x, dir.y, amplitude, angular frequency.
        vec4 makeWave(vec2 dir, float amp, float wl) {
            vec2 d = normalize(dir);
            return vec4(d, amp, 6.28318 / wl);
        }

        float wavePhase(vec2 p, float t, vec4 w, float speed) {
            return dot(w.xy, p) * w.w + t * speed;
        }

        vec3 oceanNormalHeight(vec2 pos, float t, out float h) {
            vec4 waves[7];
            float speeds[7];
            float scales[7];

            waves[0] = makeWave(vec2(1.0, 0.2), 1.8, 16.0); speeds[0] = 0.9; scales[0] = 1.0;   // hero swell
            waves[1] = makeWave(vec2(0.9, 0.35), 1.2, 11.0); speeds[1] = 1.05; scales[1] = 0.85; // second hero
            waves[2] = makeWave(vec2(-0.45, 1.0), 0.65, 6.5); speeds[2] = 1.4; scales[2] = 0.9;
            waves[3] = makeWave(vec2(0.2, 1.0), 0.45, 4.0);  speeds[3] = 1.75; scales[3] = 0.75;
            waves[4] = makeWave(vec2(1.0, -0.15), 0.35, 3.2); speeds[4] = 2.1; scales[4] = 0.7;
            waves[5] = makeWave(vec2(-0.8, 0.6), 0.28, 2.4); speeds[5] = 2.5; scales[5] = 0.65;
            waves[6] = makeWave(vec2(0.35, -1.0), 0.2, 1.8); speeds[6] = 2.9; scales[6] = 0.55;

            // gentle gusts to modulate large waves
            float gust = fbm(pos * 0.08 + vec2(t * 0.15, t * 0.07));
            float heroTilt = mix(0.9, 1.25, smoothstep(0.35, 0.75, gust));
            scales[0] *= heroTilt;
            scales[1] *= mix(0.95, 1.15, gust);

            h = 0.0;
            vec3 grad = vec3(0.0);
            for (int i = 0; i < 7; i++) {
                vec4 w = waves[i];
                float speed = speeds[i];
                float scale = scales[i];
                float phase = wavePhase(pos, t, w, speed);
                float s = sin(phase);
                float c = cos(phase);
                float amp = w.z * scale;
                h += amp * s;
                grad.x += -w.x * amp * w.w * c;
                grad.z += -w.y * amp * w.w * c;
            }

            float tilt = clamp(1.0 - 0.32 * length(grad.xz), 0.35, 1.0);
            vec3 normal = normalize(vec3(grad.x, tilt, grad.z));
            return normal;
        }

        vec3 applyFoam(vec3 color, float foam) {
            float foamLine = smoothstep(0.45, 0.75, foam);
            vec3 foamColor = vec3(0.82, 0.89, 0.97);
            return mix(color, foamColor, foamLine);
        }

        void main() {
            vec2 uv = (gl_FragCoord.xy / u_resolution.xy) * 2.0 - 1.0;
            uv.x *= u_resolution.x / u_resolution.y;

            float yaw = mix(-0.35, 0.35, u_pointer.x);
            float pitch = mix(-0.08, 0.15, u_pointer.y);

            vec3 camPos = vec3(0.0, 1.35, -4.6);
            vec3 target = vec3(0.0, 0.35, 0.0);
            float cy = cos(yaw); float sy = sin(yaw);
            float cp = cos(pitch); float sp = sin(pitch);
            vec3 forward = normalize(vec3(sy*cp, sp, cy*cp));
            vec3 right = normalize(vec3(cy, 0.0, -sy));
            vec3 up = normalize(cross(right, forward));

            vec3 rd = normalize(forward + right * uv.x + up * uv.y);

            float t = u_time * 0.4;

            // Find intersection with base ocean plane y=0
            float planeT = (0.0 - camPos.y) / rd.y;
            vec3 pos = camPos + rd * planeT;

            float h;
            vec3 n = oceanNormalHeight(pos.xz, t, h);
            pos.y = h;

            // Re-evaluate normal with small offsets for better shading
            float eps = 0.08;
            float hX; float hZ;
            vec3 nX = oceanNormalHeight(pos.xz + vec2(eps, 0.0), t, hX);
            vec3 nZ = oceanNormalHeight(pos.xz + vec2(0.0, eps), t, hZ);
            vec3 normal = normalize(cross(vec3(eps, hX - h, 0.0), vec3(0.0, hZ - h, eps)) + n * 0.6);

            vec3 lightDir = normalize(vec3(-0.55, 0.82, -0.35));
            float diff = max(dot(normal, lightDir), 0.0);

            vec3 skyTop = vec3(0.14, 0.28, 0.46);
            vec3 skyHorizon = vec3(0.05, 0.11, 0.2);
            float skyMix = clamp(0.45 + normal.y * 0.6, 0.0, 1.0);
            vec3 skyColor = mix(skyHorizon, skyTop, skyMix);

            float fresnel = pow(1.0 - max(dot(normal, -rd), 0.0), 5.0);
            vec3 baseWater = vec3(0.02, 0.09, 0.16);
            vec3 reflection = skyColor;
            vec3 color = mix(baseWater, reflection, fresnel * 0.85 + 0.12);

            vec3 halfDir = normalize(lightDir - rd);
            float spec = pow(max(dot(normal, halfDir), 0.0), 70.0) * 1.2;
            color += diff * vec3(0.22, 0.36, 0.46) + spec * vec3(0.9, 0.95, 1.0);

            float slopeFoam = smoothstep(0.5, 1.1, length(vec2(nX.y - h, nZ.y - h)));
            float foamSeed = fbm(pos.xz * 0.35 + vec2(t * 0.45, t * 0.3));
            float foam = max(foamSeed, slopeFoam);
            color = applyFoam(color, foam);

            float dist = length(pos - camPos);
            float fog = smoothstep(5.0, 18.0, dist);
            vec3 fogColor = vec3(0.03, 0.07, 0.12);
            color = mix(color, fogColor, fog);

            float vign = smoothstep(1.2, 0.4, length(uv));
            color *= vign;

            float dither = (hash(gl_FragCoord.xy + u_time) - 0.5) / 255.0;
            color += dither;

            gl_FragColor = vec4(color, 1.0);
        }
    `;

    function createShader(type, source) {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            const log = gl.getShaderInfoLog(shader) || 'Unknown shader compile error';
            const lines = source.split('\n').slice(0, 120).map((line, idx) => `${idx + 1}: ${line}`);
            console.error('Shader compile failed:', log);
            console.error('Shader source (first 120 lines):\n' + lines.join('\n'));
            gl.deleteShader(shader);
            return null;
        }
        return shader;
    }

    function createProgram(vsSource, fsSource) {
        const vs = createShader(gl.VERTEX_SHADER, vsSource);
        const fs = createShader(gl.FRAGMENT_SHADER, fsSource);
        if (!vs || !fs) {
            return null;
        }
        const program = gl.createProgram();
        gl.attachShader(program, vs);
        gl.attachShader(program, fs);
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            console.error(gl.getProgramInfoLog(program));
            gl.deleteProgram(program);
            return null;
        }
        return program;
    }

    const program = createProgram(vertexSrc, fragmentSrc);
    if (!program) {
        showError('EFFECT INIT FAILED (SHADER COMPILE)');
        return;
    }

    const positionLoc = gl.getAttribLocation(program, 'a_position');
    const timeLoc = gl.getUniformLocation(program, 'u_time');
    const resolutionLoc = gl.getUniformLocation(program, 'u_resolution');
    const pointerLoc = gl.getUniformLocation(program, 'u_pointer');

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

    let pointer = { x: 0.5, y: 0.5 };
    let lastPointer = { x: 0.5, y: 0.5 };

    function handlePointer(e) {
        const rect = canvas.getBoundingClientRect();
        const px = (e.clientX - rect.left) / rect.width;
        const py = (e.clientY - rect.top) / rect.height;
        pointer.x = Math.min(Math.max(px, 0), 1);
        pointer.y = Math.min(Math.max(py, 0), 1);
    }

    window.addEventListener('pointermove', handlePointer);
    window.addEventListener('touchmove', function(e) {
        if (e.touches && e.touches.length > 0) {
            handlePointer(e.touches[0]);
        }
    }, { passive: true });

    let width = 0;
    let height = 0;
    function resize() {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;
        const qualityScale = width <= 820 ? 0.7 : 1.0;
        const cw = Math.floor(width * dpr * qualityScale);
        const ch = Math.floor(height * dpr * qualityScale);
        canvas.width = cw;
        canvas.height = ch;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        gl.viewport(0, 0, cw, ch);
    }

    resize();
    window.addEventListener('resize', resize);

    gl.useProgram(program);
    gl.enableVertexAttribArray(positionLoc);
    gl.vertexAttribPointer(positionLoc, 2, gl.FLOAT, false, 0, 0);

    let start = performance.now();
    let ready = false;

    function draw(now) {
        const time = (now - start) / 1000;

        gl.uniform1f(timeLoc, time);
        gl.uniform2f(resolutionLoc, canvas.width, canvas.height);

        lastPointer.x += (pointer.x - lastPointer.x) * 0.05;
        lastPointer.y += (pointer.y - lastPointer.y) * 0.05;
        gl.uniform2f(pointerLoc, lastPointer.x, lastPointer.y);

        gl.drawArrays(gl.TRIANGLES, 0, 6);

        if (!ready && loading) {
            loading.classList.add('hidden');
            ready = true;
        }
        requestAnimationFrame(draw);
    }

    try {
        draw(performance.now());
    } catch (err) {
        console.error(err);
        showError('EFFECT INIT FAILED');
    }
})();
