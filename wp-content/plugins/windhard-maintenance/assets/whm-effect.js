console.log('WHM_EFFECT_VERSION=PR-2024-07-09-01');
window.__WHM_EFFECT_VERSION = 'PR-2024-07-09-01';
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
        if (canvas) {
            canvas.style.background = 'radial-gradient(circle at 50% 20%, #123247 0%, #0c1a27 60%, #08111b 100%)';
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
    }

    const gl = canvas.getContext('webgl', { antialias: false, preserveDrawingBuffer: false });
    if (!gl) {
        showError('EFFECT INIT FAILED');
        return;
    }

    const vertexSrc = `
        precision highp float;
        attribute vec2 a_position;

        uniform mat4 u_proj;
        uniform mat4 u_view;
        uniform float u_time;
        uniform vec2 u_wind;
        uniform float u_height;
        uniform vec3 u_camera;
        uniform vec2 u_angles;

        const int WAVE_COUNT = 8;
        uniform vec4 u_waveA[WAVE_COUNT]; // dir.x, dir.y, amplitude, steepness
        uniform vec4 u_waveB[WAVE_COUNT]; // wavelength, speed, phase, unused

        varying vec3 v_world;
        varying vec3 v_normal;
        varying float v_foam;

        float hash11(float p) {
            return fract(sin(p * 17.13) * 43758.5453);
        }

        void main() {
            vec2 posXZ = a_position;
            float t = u_time;
            vec3 displaced = vec3(posXZ.x, 0.0, posXZ.y);
            vec3 normal = vec3(0.0, 1.0, 0.0);
            float foam = 0.0;

            for (int i = 0; i < WAVE_COUNT; i++) {
                vec4 wa = u_waveA[i];
                vec4 wb = u_waveB[i];
                vec2 dir = normalize(wa.xy + u_wind * 0.12);
                float amp = wa.z * u_height;
                float steep = wa.w;
                float k = 6.28318 / wb.x;
                float speed = wb.y;
                float phase = dot(dir, posXZ) * k + t * speed + wb.z;
                float s = sin(phase);
                float c = cos(phase);
                float q = steep / float(WAVE_COUNT);

                displaced.x += dir.x * (q * amp * c);
                displaced.z += dir.y * (q * amp * c);
                displaced.y += amp * s;

                normal.x -= dir.x * amp * k * s;
                normal.z -= dir.y * amp * k * s;
                normal.y += steep * amp * c;

                float crest = smoothstep(0.55, 1.05, abs(s) * steep);
                foam += crest;
            }

            float tiltMod = mix(1.0, 0.85, clamp(length(u_wind) * 0.6, 0.0, 1.0));
            normal = normalize(normal * vec3(1.0, tiltMod, 1.0));
            foam = clamp(foam / float(WAVE_COUNT), 0.0, 1.0);

            v_world = displaced;
            v_normal = normal;
            v_foam = foam;

            gl_Position = u_proj * u_view * vec4(displaced, 1.0);
        }
    `;

    const fragmentSrc = `
        precision highp float;
        varying vec3 v_world;
        varying vec3 v_normal;
        varying float v_foam;

        uniform vec2 u_resolution;
        uniform float u_time;
        uniform vec3 u_camera;
        uniform vec3 u_light;
        uniform vec2 u_angles;

        float hash(vec2 p) {
            p = fract(p * vec2(123.34, 456.21));
            p += dot(p, p + 45.32);
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
            for (int i = 0; i < 4; i++) {
                v += a * noise(p);
                p *= 2.05;
                a *= 0.55;
            }
            return v;
        }

        vec3 microNormal(vec2 pos, float t) {
            float dHx = 0.0;
            float dHz = 0.0;

            vec2 d1 = normalize(vec2(1.0, 0.35));
            vec2 d2 = normalize(vec2(-0.65, 1.0));
            vec2 d3 = normalize(vec2(0.2, -1.0));
            vec2 d4 = normalize(vec2(-1.0, -0.25));

            float f1 = 5.2; float s1 = 1.8; float a1 = 0.22;
            float f2 = 8.5; float s2 = 2.2; float a2 = 0.18;
            float f3 = 12.0; float s3 = 3.1; float a3 = 0.14;
            float f4 = 16.5; float s4 = 3.8; float a4 = 0.11;

            float ph1 = dot(d1, pos) * f1 + t * s1;
            float ph2 = dot(d2, pos) * f2 + t * s2;
            float ph3 = dot(d3, pos) * f3 + t * s3;
            float ph4 = dot(d4, pos) * f4 + t * s4;

            dHx += d1.x * cos(ph1) * a1 * f1;
            dHz += d1.y * cos(ph1) * a1 * f1;
            dHx += d2.x * cos(ph2) * a2 * f2;
            dHz += d2.y * cos(ph2) * a2 * f2;
            dHx += d3.x * cos(ph3) * a3 * f3;
            dHz += d3.y * cos(ph3) * a3 * f3;
            dHx += d4.x * cos(ph4) * a4 * f4;
            dHz += d4.y * cos(ph4) * a4 * f4;

            vec2 hRipples = pos * 2.4;
            float r1 = sin(dot(hRipples, vec2(1.3, 1.1)) + t * 1.9) * 0.08;
            float r2 = sin(dot(hRipples, vec2(-1.5, 0.7)) + t * 2.7) * 0.06;
            dHx += r1 * 5.5;
            dHz += r2 * 5.5;

            return normalize(vec3(-dHx, 1.0, -dHz));
        }

        void main() {
            vec3 N = normalize(v_normal);
            vec3 V = normalize(u_camera - v_world);
            vec3 L = normalize(u_light);
            vec3 H = normalize(L + V);

            vec3 microN = microNormal(v_world.xz * 0.5, u_time * 0.85);
            N = normalize(mix(N, microN, 0.58));

            float diff = max(dot(N, L), 0.0);
            float fresnelBase = 0.035;
            float fresnel = fresnelBase + (1.0 - fresnelBase) * pow(1.0 - max(dot(N, V), 0.0), 5.0);

            vec3 waterDeep = vec3(0.025, 0.07, 0.13);
            vec3 waterShallow = vec3(0.07, 0.18, 0.27);
            float viewLift = clamp(0.25 + V.y * 0.65, 0.0, 1.0);
            vec3 base = mix(waterDeep, waterShallow, viewLift);

            vec3 skyTop = vec3(0.26, 0.4, 0.62);
            vec3 skyHorizon = vec3(0.12, 0.18, 0.3);
            vec3 R = reflect(-V, N);
            float skyV = clamp(0.52 + R.y * 0.48, 0.0, 1.0);
            vec3 reflection = mix(skyHorizon, skyTop, skyV);

            float spec = pow(max(dot(N, H), 0.0), 180.0);
            float rough = 0.35 + 0.35 * noise(v_world.xz * 1.1 + u_time * 0.35);
            vec3 specColor = mix(vec3(0.55, 0.66, 0.78), vec3(0.9, 0.96, 1.0), rough);
            vec3 color = mix(base, reflection, fresnel);
            color += diff * vec3(0.12, 0.21, 0.32) + spec * specColor;

            float foamNoise = fbm(v_world.xz * 0.35 + u_time * vec2(0.32, 0.2) + u_angles * 3.0);
            float foamLine = smoothstep(0.6, 0.95, v_foam + foamNoise * 0.45);
            vec3 foamColor = vec3(0.82, 0.9, 0.98);
            color = mix(color, foamColor, foamLine);

            float dist = length(u_camera - v_world);
            float fog = smoothstep(14.0, 46.0, dist);
            vec3 fogColor = mix(vec3(0.045, 0.08, 0.13), skyHorizon, 0.38);
            color = mix(color, fogColor, fog);

            vec2 uv = gl_FragCoord.xy / u_resolution.xy;
            float vign = smoothstep(1.28, 0.62, length(uv - 0.5));
            color *= mix(0.94, 1.0, vign);

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
            const lines = source.split('\n').slice(0, 180).map((line, idx) => `${idx + 1}: ${line}`);
            console.error('Shader compile failed:', log);
            console.error('Shader source (first 180 lines):\n' + lines.join('\n'));
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

    const attribPosition = gl.getAttribLocation(program, 'a_position');
    const uniProj = gl.getUniformLocation(program, 'u_proj');
    const uniView = gl.getUniformLocation(program, 'u_view');
    const uniTime = gl.getUniformLocation(program, 'u_time');
    const uniRes = gl.getUniformLocation(program, 'u_resolution');
    const uniWind = gl.getUniformLocation(program, 'u_wind');
    const uniHeight = gl.getUniformLocation(program, 'u_height');
    const uniCamera = gl.getUniformLocation(program, 'u_camera');
    const uniLight = gl.getUniformLocation(program, 'u_light');
    const uniAngles = gl.getUniformLocation(program, 'u_angles');
    const uniWaveA = gl.getUniformLocation(program, 'u_waveA[0]');
    const uniWaveB = gl.getUniformLocation(program, 'u_waveB[0]');

    const waveA = new Float32Array([
        // Hero long swells
        0.92, 0.18, 2.3, 0.82,
        -0.8, 0.25, 2.1, 0.78,
        // Mid waves
        -0.55, 1.0, 1.35, 0.7,
        0.15, 1.0, 1.05, 0.64,
        0.98, -0.24, 0.82, 0.55,
        -0.78, 0.48, 0.72, 0.5,
        0.32, -1.0, 0.64, 0.48,
        -0.25, -0.85, 0.56, 0.46,
    ]);
    const waveB = new Float32Array([
        34.0, 0.62, 0.0, 0.0,
        27.0, 0.74, 1.05, 0.0,
        17.0, 1.05, 2.1, 0.0,
        12.0, 1.35, 3.2, 0.0,
        8.6, 1.75, 4.3, 0.0,
        6.4, 2.05, 5.4, 0.0,
        5.1, 2.35, 6.4, 0.0,
        4.1, 2.6, 7.5, 0.0,
    ]);

    function buildGrid(resolution, span) {
        const verts = [];
        const indices = [];
        for (let y = 0; y <= resolution; y++) {
            for (let x = 0; x <= resolution; x++) {
                const u = x / resolution;
                const v = y / resolution;
                const px = (u - 0.5) * span;
                const pz = (v - 0.5) * span;
                verts.push(px, pz);
            }
        }
        const row = resolution + 1;
        for (let y = 0; y < resolution; y++) {
            for (let x = 0; x < resolution; x++) {
                const i = y * row + x;
                indices.push(i, i + 1, i + row);
                indices.push(i + 1, i + row + 1, i + row);
            }
        }
        const vertArray = new Float32Array(verts);
        const IndexArray = (verts.length / 2 > 65000) ? Uint32Array : Uint16Array;
        return { positions: vertArray, indices: new IndexArray(indices) };
    }

    function perspective(fov, aspect, near, far) {
        const f = 1.0 / Math.tan(fov / 2);
        const nf = 1 / (near - far);
        return new Float32Array([
            f / aspect, 0, 0, 0,
            0, f, 0, 0,
            0, 0, (far + near) * nf, -1,
            0, 0, (2 * far * near) * nf, 0,
        ]);
    }

    function lookAt(eye, target, up) {
        const zx = eye[0] - target[0];
        const zy = eye[1] - target[1];
        const zz = eye[2] - target[2];
        let zlen = Math.hypot(zx, zy, zz);
        const zxN = zx / zlen; const zyN = zy / zlen; const zzN = zz / zlen;

        let xx = up[1] * zzN - up[2] * zyN;
        let xy = up[2] * zxN - up[0] * zzN;
        let xz = up[0] * zyN - up[1] * zxN;
        let xlen = Math.hypot(xx, xy, xz);
        xx /= xlen; xy /= xlen; xz /= xlen;

        const yx = zyN * xz - zzN * xy;
        const yy = zzN * xx - zxN * xz;
        const yz = zxN * xy - zyN * xx;

        return new Float32Array([
            xx, yx, zxN, 0,
            xy, yy, zyN, 0,
            xz, yz, zzN, 0,
            -(xx * eye[0] + xy * eye[1] + xz * eye[2]),
            -(yx * eye[0] + yy * eye[1] + yz * eye[2]),
            -(zxN * eye[0] + zyN * eye[1] + zzN * eye[2]),
            1,
        ]);
    }

    const gridRes = Math.min(Math.max(144, Math.floor(Math.min(window.innerWidth, window.innerHeight) * 0.2)), 240);
    const gridSpan = 16.5;
    const mesh = buildGrid(gridRes, gridSpan);

    const vao = gl.createVertexArray ? gl.createVertexArray() : null;
    if (vao && gl.bindVertexArray) {
        gl.bindVertexArray(vao);
    }

    const positionBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, mesh.positions, gl.STATIC_DRAW);

    const indexBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, indexBuffer);
    gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, mesh.indices, gl.STATIC_DRAW);

    gl.enableVertexAttribArray(attribPosition);
    gl.vertexAttribPointer(attribPosition, 2, gl.FLOAT, false, 0, 0);

    if (vao && gl.bindVertexArray) {
        gl.bindVertexArray(null);
    }

    gl.useProgram(program);
    gl.uniform4fv(uniWaveA, waveA);
    gl.uniform4fv(uniWaveB, waveB);
    gl.uniform3f(uniLight, -0.55, 0.82, -0.35);
    gl.uniform1f(uniHeight, prefersReduce ? 0.65 : 1.0);

    const windVec = [0.6, 0.25];
    gl.uniform2f(uniWind, windVec[0], windVec[1]);

    let width = 0;
    let height = 0;
    function resize() {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;
        const qualityScale = width <= 820 ? 0.8 : 1.0;
        const cw = Math.floor(width * dpr * qualityScale);
        const ch = Math.floor(height * dpr * qualityScale);
        canvas.width = cw;
        canvas.height = ch;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        gl.viewport(0, 0, cw, ch);
        gl.uniform2f(uniRes, cw, ch);
    }

    resize();
    window.addEventListener('resize', resize);

    let yaw = 0.12;
    let pitch = -0.08;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;
    function onPointerDown(e) {
        dragging = true;
        lastX = e.clientX;
        lastY = e.clientY;
    }
    function onPointerUp() { dragging = false; }
    function onPointerMove(e) {
        if (!dragging) return;
        const dx = e.clientX - lastX;
        const dy = e.clientY - lastY;
        yaw += dx * 0.0022;
        pitch += dy * 0.002;
        pitch = Math.max(-0.22, Math.min(0.25, pitch));
        lastX = e.clientX;
        lastY = e.clientY;
    }

    canvas.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointermove', onPointerMove);

    window.addEventListener('touchstart', function(e) {
        if (e.touches && e.touches.length > 0) {
            onPointerDown(e.touches[0]);
        }
    }, { passive: true });
    window.addEventListener('touchend', onPointerUp, { passive: true });
    window.addEventListener('touchmove', function(e) {
        if (e.touches && e.touches.length > 0) {
            onPointerMove(e.touches[0]);
        }
    }, { passive: true });

    let paused = false;
    function togglePause() {
        paused = !paused;
        if (!paused) {
            start = performance.now() - time * 1000;
        }
    }

    function toggleFullscreen() {
        const docEl = document.documentElement;
        if (!document.fullscreenElement && docEl.requestFullscreen) {
            docEl.requestFullscreen();
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }

    window.addEventListener('keydown', function(e) {
        if (e.code === 'Space') {
            e.preventDefault();
            togglePause();
        }
        if (e.key === 'f' || e.key === 'F') {
            toggleFullscreen();
        }
    });

    let start = performance.now();
    let time = 0;
    let ready = false;
    const timeScale = prefersReduce ? 0.35 : 1.0;

    function draw(now) {
        if (!paused) {
            time = (now - start) / 1000 * timeScale;
        }

        const drift = prefersReduce ? 0.0 : 0.018;
        yaw += Math.sin(time * 0.12) * drift;
        pitch += Math.cos(time * 0.08) * drift * 0.24;
        pitch = Math.max(-0.24, Math.min(0.26, pitch));

        const camDist = 6.5;
        const camHeight = 1.05 + Math.sin(time * 0.12) * 0.07;
        const cy = Math.cos(yaw); const sy = Math.sin(yaw);
        const cp = Math.cos(pitch); const sp = Math.sin(pitch);
        const eye = [Math.sin(yaw) * camDist * cp, camHeight + sp * 0.35, -Math.cos(yaw) * camDist * cp];
        const target = [0, 0.16 + sp * 0.28, 0];
        const view = lookAt(eye, target, [0, 1, 0]);
        const proj = perspective((60 * Math.PI) / 180, canvas.width / canvas.height, 0.1, 60.0);

        gl.useProgram(program);
        gl.uniformMatrix4fv(uniProj, false, proj);
        gl.uniformMatrix4fv(uniView, false, view);
        gl.uniform1f(uniTime, time);
        gl.uniform3f(uniCamera, eye[0], eye[1], eye[2]);
        gl.uniform2f(uniAngles, yaw, pitch);

        if (vao && gl.bindVertexArray) {
            gl.bindVertexArray(vao);
        } else {
            gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
            gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, indexBuffer);
            gl.enableVertexAttribArray(attribPosition);
            gl.vertexAttribPointer(attribPosition, 2, gl.FLOAT, false, 0, 0);
        }

        gl.enable(gl.DEPTH_TEST);
        gl.clearColor(0.03, 0.06, 0.1, 1.0);
        gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
        gl.drawElements(gl.TRIANGLES, mesh.indices.length, mesh.indices instanceof Uint32Array ? gl.UNSIGNED_INT : gl.UNSIGNED_SHORT, 0);

        if (vao && gl.bindVertexArray) {
            gl.bindVertexArray(null);
        }

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
