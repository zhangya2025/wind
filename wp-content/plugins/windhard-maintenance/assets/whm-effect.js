console.log('WHM_EFFECT_VERSION=PR-2024-07-20-01');
window.__WHM_EFFECT_VERSION = 'PR-2024-07-20-01';
window.__WHM_EFFECT_EXPECTED = window.__WHM_EFFECT_VERSION;

(function() {
    let canvas = document.getElementById('whm-effect');
    const loading = document.getElementById('whm-loading');
    const errorNode = document.getElementById('whm-effect-error');
    const watermark = document.getElementById('whm-effect-watermark');
    const body = document.body;

    if (watermark) {
        watermark.textContent = 'EFFECT_EXPECTED: ' + window.__WHM_EFFECT_VERSION;
    }

    function showError(message, detail) {
        if (errorNode) {
            errorNode.textContent = detail ? `${message} — ${detail}` : message;
            errorNode.classList.remove('hidden');
        }
        if (loading) {
            loading.textContent = detail ? `${message} — ${detail}` : message;
            loading.classList.remove('hidden');
        }
        if (canvas) {
            canvas.style.background = 'linear-gradient(180deg, #0d1c2f 0%, #0a1625 60%, #050b15 100%)';
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

    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const glAttributes = { antialias: false, premultipliedAlpha: false, preserveDrawingBuffer: false };
    const gl = canvas.getContext('webgl2', glAttributes) || canvas.getContext('webgl', glAttributes) || canvas.getContext('experimental-webgl', glAttributes);
    const isWebGL2 = typeof WebGL2RenderingContext !== 'undefined' && gl instanceof WebGL2RenderingContext;

    function resize() {
        const width = Math.floor(window.innerWidth * dpr);
        const height = Math.floor(window.innerHeight * dpr);
        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        if (gl) {
            gl.viewport(0, 0, width, height);
        }
    }

    if (!gl) {
        showError('EFFECT INIT FAILED');
        fallback2D();
        return;
    }

    console.log('[WHM] WHM_GL_CONTEXT', isWebGL2 ? 'webgl2' : 'webgl1');
    console.log('[WHM] WHM_GLSL', gl.getParameter(gl.SHADING_LANGUAGE_VERSION));

    resize();
    window.addEventListener('resize', resize);

    function formatSource(source, maxLines = 120) {
        const lines = source.split('\n');
        return lines.slice(0, maxLines).map((line, idx) => `${idx + 1}: ${line}`).join('\n');
    }

    function compileShader(type, source) {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            const info = gl.getShaderInfoLog(shader) || '(no info)';
            const numbered = formatSource(source, 120);
            const typeLabel = type === gl.VERTEX_SHADER ? 'VERTEX' : 'FRAGMENT';
            console.error('[WHM] SHADER_COMPILE_FAILED', { type: typeLabel, info });
            console.error('[WHM] SHADER_SOURCE_WITH_LINENO\n' + numbered);
            window.__WHM_SHADER_FAIL = { type: typeLabel, info, source: numbered };
            showError('EFFECT INIT FAILED', 'SHADER_COMPILE_FAILED: ' + info.split('\n')[0]);
            gl.deleteShader(shader);
            throw new Error('SHADER COMPILE FAILED');
        }
        return shader;
    }

    function createProgram(vsSrc, fsSrc) {
        const vs = compileShader(gl.VERTEX_SHADER, vsSrc);
        const fs = compileShader(gl.FRAGMENT_SHADER, fsSrc);
        const program = gl.createProgram();
        gl.attachShader(program, vs);
        gl.attachShader(program, fs);
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            const info = gl.getProgramInfoLog(program) || '(no info)';
            console.error('[WHM] PROGRAM_LINK_FAILED', { info });
            window.__WHM_SHADER_FAIL = { type: 'PROGRAM', info };
            showError('EFFECT INIT FAILED', 'PROGRAM_LINK_FAILED: ' + info.split('\n')[0]);
            gl.deleteProgram(program);
            throw new Error('PROGRAM LINK FAILED');
        }
        return program;
    }

    const vertexSrc = isWebGL2
        ? "#version 300 es\n" +
          "in vec2 a_position;\n" +
          "out vec2 v_uv;\n" +
          "void main() {\n" +
          "    v_uv = a_position * 0.5 + 0.5;\n" +
          "    gl_Position = vec4(a_position, 0.0, 1.0);\n" +
          "}\n"
        : "attribute vec2 a_position;\n" +
          "varying vec2 v_uv;\n" +
          "void main() {\n" +
          "    v_uv = a_position * 0.5 + 0.5;\n" +
          "    gl_Position = vec4(a_position, 0.0, 1.0);\n" +
          "}\n";

    const fragmentHeader = isWebGL2
        ? "#version 300 es\n" +
          "precision highp float;\n" +
          "in vec2 v_uv;\n" +
          "out vec4 outColor;\n"
        : "precision highp float;\n" +
          "varying vec2 v_uv;\n";

    const fragmentBody = `
        uniform vec2 u_resolution;
        uniform float u_time;
        uniform float u_reduce;

        float hash(vec2 p) {
            p = fract(p * vec2(123.34, 234.12));
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
                p *= 2.02;
                a *= 0.55;
            }
            return v;
        }

        float heightfield(float x, float t, float scale, float amp) {
            float warp = fbm(vec2(x * scale * 0.2 + t * 0.03, t * 0.05)) * 0.15;
            float h = fbm(vec2(x * scale + warp, 0.0) + vec2(t * 0.05, 0.0));
            return h * amp;
        }

        vec3 mountainColor(float h, float fog, float contrast) {
            vec3 rock = vec3(0.28, 0.33, 0.40) * contrast;
            vec3 snow = vec3(0.9, 0.93, 0.97);
            float snowMask = smoothstep(0.25, 0.65, h);
            vec3 col = mix(rock, snow, snowMask);
            return mix(col, vec3(0.93, 0.95, 0.97), fog);
        }

        float flake(vec2 uv, float size, float softness) {
            float d = length(uv);
            return exp(-pow(d / size, 2.0) * softness);
        }

        float snowLayer(vec2 uv, float t, float density, float speed, float wind, float size, float softness, float swing, vec2 quietCenter, float quietRadius) {
            vec2 p = uv;
            p.x += sin(p.y * 6.2831 + t * 0.5) * swing;
            p.y += t * speed;
            p.x += t * wind;
            p *= density;
            vec2 id = floor(p);
            vec2 f = fract(p) - 0.5;
            vec2 jitter = vec2(hash(id + 0.17), hash(id + 2.83)) - 0.5;
            vec2 drop = f + jitter * 0.65;
            float base = flake(drop, size, softness);
            float quiet = 1.0 - smoothstep(quietRadius * 0.6, quietRadius, distance(uv, quietCenter));
            return base * (1.0 - quiet * 0.75);
        }

        void main() {
            vec2 uv = v_uv;
            float aspect = u_resolution.x / max(u_resolution.y, 1.0);
            vec2 p = (gl_FragCoord.xy / u_resolution) * 2.0 - 1.0;
            p.x *= aspect;
            float t = u_time * mix(1.0, 0.5, u_reduce);

            vec3 skyTop = vec3(0.09, 0.13, 0.20);
            vec3 skyHorizon = vec3(0.78, 0.84, 0.90);
            float horizon = smoothstep(-0.2, 0.7, uv.y);
            vec3 sky = mix(skyHorizon, skyTop, horizon);

            vec3 col = sky;
            vec2 quietCenter = vec2(0.5, 0.55);
            float quietRadius = 0.25;

            float fogCurve = smoothstep(0.25, 0.95, uv.y);
            float layers[4];
            layers[0] = heightfield(uv.x * 1.4 - 0.2, t * 0.25, 1.2, 0.35);
            layers[1] = heightfield(uv.x * 1.8 + 0.1, t * 0.32, 1.6, 0.42);
            layers[2] = heightfield(uv.x * 2.2 - 0.15, t * 0.4, 2.3, 0.5);
            layers[3] = heightfield(uv.x * 2.8 + 0.05, t * 0.55, 3.1, 0.6);

            float bases[4];
            bases[0] = 0.28;
            bases[1] = 0.32;
            bases[2] = 0.36;
            bases[3] = 0.40;

            float fogs[4];
            fogs[0] = fogCurve * 0.75;
            fogs[1] = fogCurve * 0.6;
            fogs[2] = fogCurve * 0.45;
            fogs[3] = fogCurve * 0.3;

            float contrasts[4];
            contrasts[0] = 0.55;
            contrasts[1] = 0.7;
            contrasts[2] = 0.85;
            contrasts[3] = 1.0;

            for (int i = 0; i < 4; i++) {
                float h = layers[i];
                float base = bases[i];
                float ridgeY = h + base;
                float mask = smoothstep(ridgeY + 0.02, ridgeY - 0.05, uv.y);
                vec3 mcol = mountainColor(h, fogs[i], contrasts[i]);
                col = mix(col, mcol, mask * (0.45 + 0.15 * float(i)));
            }

            float snowfield = smoothstep(0.35, 0.5, uv.y);
            vec3 snowBase = vec3(0.93, 0.95, 0.98);
            float fieldNoise = fbm(vec2(uv.x * 6.0, uv.y * 2.0 + t * 0.05)) * 0.03;
            col = mix(snowBase + fieldNoise, col, snowfield);

            float snowFar = snowLayer(uv * vec2(1.05, 1.0), t * 0.25, 16.0, -0.05, -0.04, 0.05, 16.0, 0.15, quietCenter, quietRadius);
            float snowMid = snowLayer(uv, t * 0.35, 12.0, -0.12, -0.08, 0.07, 18.0, 0.25, quietCenter, quietRadius);
            float snowNear = snowLayer(uv * vec2(0.9, 1.0), t * 0.5, 9.0, -0.18, -0.12, 0.1, 22.0, 0.35, quietCenter, quietRadius);
            float snowFront = snowLayer(uv * vec2(0.8, 1.0), t * 0.65, 6.5, -0.22, -0.16, 0.13, 26.0, 0.45, quietCenter, quietRadius);
            float snowMix = clamp(snowFar + snowMid + snowNear + snowFront, 0.0, 1.2);
            vec3 snowColor = vec3(0.96, 0.98, 1.0);
            col = mix(col, snowColor, snowMix);

            float haze = smoothstep(0.3, 0.95, uv.y);
            col = mix(col, skyHorizon, haze * 0.4);
            float vign = smoothstep(1.1, 0.35, length(p));
            col *= mix(0.88, 1.0, vign);

            gl_FragColor = vec4(col, 1.0);
        }
    `;

    const fragmentSrc = fragmentHeader + fragmentBody.replace(/gl_FragColor/g, isWebGL2 ? 'outColor' : 'gl_FragColor');

    let program;
    try {
        program = createProgram(vertexSrc, fragmentSrc);
    } catch (err) {
        console.error(err);
        if (!window.__WHM_SHADER_FAIL) {
            showError('EFFECT INIT FAILED');
        }
        fallback2D();
        return;
    }

    const attribPosition = gl.getAttribLocation(program, 'a_position');
    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([
        -1, -1, 1, -1, -1, 1,
        -1, 1, 1, -1, 1, 1
    ]), gl.STATIC_DRAW);

    gl.useProgram(program);
    gl.enableVertexAttribArray(attribPosition);
    gl.vertexAttribPointer(attribPosition, 2, gl.FLOAT, false, 0, 0);

    const uResolution = gl.getUniformLocation(program, 'u_resolution');
    const uTime = gl.getUniformLocation(program, 'u_time');
    const uReduce = gl.getUniformLocation(program, 'u_reduce');

    let start = performance.now();
    let running = true;

    function render() {
        if (!running) return;
        const now = performance.now();
        const t = (now - start) * 0.001;
        gl.viewport(0, 0, canvas.width, canvas.height);
        gl.uniform2f(uResolution, canvas.width, canvas.height);
        gl.uniform1f(uTime, t);
        gl.uniform1f(uReduce, prefersReduce ? 1.0 : 0.0);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
        if (loading) {
            loading.classList.add('hidden');
        }
        requestAnimationFrame(render);
    }

    try {
        render();
    } catch (err) {
        console.error('Render error', err);
        showError('EFFECT INIT FAILED');
        fallback2D();
    }

    function fallback2D() {
        window.removeEventListener('resize', resize);
        running = false;
        if (!canvas) return;
        let targetCanvas = canvas;
        if (gl) {
            const replacement = canvas.cloneNode(false);
            replacement.id = canvas.id;
            const parent = canvas.parentNode;
            if (parent) {
                parent.replaceChild(replacement, canvas);
            }
            targetCanvas = replacement;
            canvas = replacement;
        }

        const ctx = targetCanvas.getContext('2d');
        if (!ctx) return;
        let w = targetCanvas.width;
        let h = targetCanvas.height;
        function resize2d() {
            w = Math.floor(window.innerWidth * dpr);
            h = Math.floor(window.innerHeight * dpr);
            targetCanvas.width = w;
            targetCanvas.height = h;
            targetCanvas.style.width = '100%';
            targetCanvas.style.height = '100%';
        }
        resize2d();
        window.addEventListener('resize', resize2d);

        const layers = [
            { color: 'rgba(210,220,230,0.5)', offset: 0.62, rough: 0.2 },
            { color: 'rgba(200,210,225,0.7)', offset: 0.58, rough: 0.25 },
            { color: 'rgba(190,205,220,0.8)', offset: 0.54, rough: 0.3 },
            { color: 'rgba(175,195,215,0.9)', offset: 0.50, rough: 0.35 }
        ];

        const flakes = [];
        for (let i = 0; i < 220; i++) {
            flakes.push({
                x: Math.random() * w,
                y: Math.random() * h,
                r: 1 + Math.random() * 2.5,
                s: 0.5 + Math.random() * 1.8,
                drift: -0.3 - Math.random() * 0.4
            });
        }

        function draw2d() {
            ctx.clearRect(0, 0, w, h);
            const grd = ctx.createLinearGradient(0, 0, 0, h);
            grd.addColorStop(0, '#0b1a2f');
            grd.addColorStop(0.6, '#1a2c44');
            grd.addColorStop(1, '#e5ebf3');
            ctx.fillStyle = grd;
            ctx.fillRect(0, 0, w, h);

            for (const layer of layers) {
                ctx.fillStyle = layer.color;
                ctx.beginPath();
                ctx.moveTo(0, h * layer.offset);
                ctx.lineTo(w * 0.25, h * (layer.offset - layer.rough * 0.2));
                ctx.lineTo(w * 0.55, h * (layer.offset + layer.rough * 0.15));
                ctx.lineTo(w, h * (layer.offset - layer.rough * 0.1));
                ctx.lineTo(w, h);
                ctx.lineTo(0, h);
                ctx.closePath();
                ctx.fill();
            }

            ctx.fillStyle = '#f5f8fc';
            ctx.fillRect(0, h * 0.58, w, h * 0.42);

            ctx.fillStyle = 'rgba(255,255,255,0.92)';
            const quietCenter = { x: w * 0.5, y: h * 0.55 };
            const quietRadius = Math.min(w, h) * 0.22;
            for (const f of flakes) {
                const dx = f.x - quietCenter.x;
                const dy = f.y - quietCenter.y;
                const quiet = 1 - Math.min(1, Math.max(0, (Math.hypot(dx, dy) - quietRadius * 0.6) / (quietRadius - quietRadius * 0.6)));
                const alpha = 0.9 - quiet * 0.7;
                ctx.globalAlpha = alpha;
                ctx.beginPath();
                ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalAlpha = 1;
                f.y += f.s * 1.4;
                f.x += f.drift + Math.sin(f.y * 0.01) * 0.6;
                if (f.y > h) {
                    f.y = -5;
                    f.x = Math.random() * w;
                }
            }
            if (loading) {
                loading.classList.add('hidden');
            }
            requestAnimationFrame(draw2d);
        }
        draw2d();
    }
})();
