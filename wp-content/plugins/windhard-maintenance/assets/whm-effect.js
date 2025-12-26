console.log('WHM_EFFECT_VERSION=PR-2024-06-01-01');
window.__WHM_EFFECT_VERSION = 'PR-2024-06-01-01';
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

        struct Wave {
            vec2 dir;
            float amp;
            float freq;
            float speed;
        };

        Wave wave(vec2 dir, float amp, float wl, float speed) {
            Wave w;
            w.dir = normalize(dir);
            w.amp = amp;
            w.freq = 6.28318 / wl;
            w.speed = speed;
            return w;
        }

        vec3 waveNormal(vec2 p, float t, Wave w) {
            float phase = dot(w.dir, p) * w.freq + t * w.speed;
            float s = sin(phase);
            float c = cos(phase);
            float dx = -w.dir.x * w.amp * w.freq * c;
            float dz = -w.dir.y * w.amp * w.freq * c;
            vec3 n = normalize(vec3(dx, 1.0 - s * w.amp * w.freq, dz));
            return n;
        }

        float waveHeight(vec2 p, float t, Wave w) {
            float phase = dot(w.dir, p) * w.freq + t * w.speed;
            return w.amp * sin(phase);
        }

        vec3 oceanNormalHeight(vec2 pos, float t, out float h) {
            Wave w1 = wave(vec2(1.0, 0.3), 0.8, 6.5, 1.2);
            Wave w2 = wave(vec2(-0.4, 1.0), 0.45, 3.5, 1.8);
            Wave w3 = wave(vec2(0.2, 1.0), 0.25, 1.6, 2.4);
            Wave hero = wave(vec2(1.0, 0.1), 1.6, 12.0, 0.9);

            float heroBlend = smoothstep(0.0, 1.2, fbm(pos * 0.05 + t * 0.08));

            h = 0.0;
            h += waveHeight(pos, t, w1) * mix(1.0, 1.25, heroBlend);
            h += waveHeight(pos, t, w2);
            h += waveHeight(pos, t, w3 * 0.8);
            h += waveHeight(pos * 0.7, t * 0.8, hero) * 1.4;

            vec3 n = waveNormal(pos, t, w1);
            n += waveNormal(pos, t, w2) * 0.7;
            n += waveNormal(pos, t, w3) * 0.6;
            n += waveNormal(pos * 0.7, t * 0.8, hero) * 1.3;
            return normalize(n);
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

            vec3 camPos = vec3(0.0, 1.6, -5.0);
            vec3 target = vec3(0.0, 0.4, 0.0);
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
            float eps = 0.1;
            float hX; float hZ;
            vec3 nX = oceanNormalHeight(pos.xz + vec2(eps, 0.0), t, hX);
            vec3 nZ = oceanNormalHeight(pos.xz + vec2(0.0, eps), t, hZ);
            vec3 normal = normalize(cross(vec3(eps, hX - h, 0.0), vec3(0.0, hZ - h, eps)) + n);

            vec3 lightDir = normalize(vec3(-0.6, 0.8, -0.4));
            float diff = max(dot(normal, lightDir), 0.0);

            vec3 skyTop = vec3(0.12, 0.25, 0.42);
            vec3 skyHorizon = vec3(0.04, 0.1, 0.18);
            float skyMix = clamp(0.5 + normal.y * 0.5, 0.0, 1.0);
            vec3 skyColor = mix(skyHorizon, skyTop, skyMix);

            float fresnel = pow(1.0 - max(dot(normal, -rd), 0.0), 5.0);
            vec3 baseWater = vec3(0.03, 0.1, 0.18);
            vec3 reflection = skyColor;
            vec3 color = mix(baseWater, reflection, fresnel * 0.8 + 0.1);
            color += diff * vec3(0.2, 0.35, 0.45);

            float foamSeed = fbm(pos.xz * 0.4 + vec2(t * 0.5, t * 0.3));
            float foam = foamSeed + smoothstep(0.55, 0.9, abs(normal.y - 0.3));
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
            console.error(gl.getShaderInfoLog(shader));
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
        showError('EFFECT INIT FAILED');
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
