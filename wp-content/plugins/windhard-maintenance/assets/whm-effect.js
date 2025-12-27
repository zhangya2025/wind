console.log('WHM_EFFECT_VERSION=PR-2024-07-19-02');
window.__WHM_EFFECT_VERSION = 'PR-2024-07-19-02';
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

        float ridge(vec2 p) {
            return 1.0 - abs(fbm(p) * 2.0 - 1.0);
        }

        vec3 mountainLayer(vec2 uv, float t, float scale, float height, float fog) {
            float offset = t * 0.02 * scale;
            vec2 ridgeUv = vec2(uv.x * scale + offset, uv.y * 0.35);
            float h = ridge(ridgeUv) * height;
            float eps = max(1.0 / max(u_resolution.x, 1.0), 1.0 / max(u_resolution.y, 1.0));
            float hx = ridge(ridgeUv + vec2(eps, 0.0)) * height;
            float hy = ridge(ridgeUv + vec2(0.0, eps)) * height;
            float base = 0.15 + height * 0.5;
            float y = uv.y;
            float mask = smoothstep(h + base + 0.01, h + base - 0.04, y);
            float slope = clamp((abs(hx - h) + abs(hy - h)) / eps, 0.0, 1.0);
            float snow = smoothstep(0.2, 0.6, h) * (1.0 - slope * 0.5);
            vec3 rock = vec3(0.25, 0.32, 0.42);
            vec3 ice = vec3(0.82, 0.88, 0.94);
            vec3 col = mix(rock, ice, snow);
            col *= mix(0.6, 1.0, 1.0 - fog);
            return col * mask;
        }

        float snowflake(vec2 uv, float size, float soften) {
            float d = length(uv);
            float alpha = exp(-pow(d / size, 2.0) * soften);
            return alpha;
        }

        float layeredSnow(vec2 uv, float t, float scale, float speed, float wind, float size, float amount, float swing) {
            vec2 p = uv;
            p.x += sin(p.y * 6.2831 + t * 0.3) * swing;
            p.y += t * speed;
            p.x += t * wind;
            p *= scale;
            vec2 id = floor(p);
            vec2 f = fract(p) - 0.5;
            float n = hash(id + vec2(0.0, floor(t)));
            vec2 jitter = vec2(hash(id + 1.3), hash(id + 2.7)) - 0.5;
            vec2 drop = f + jitter * 0.6;
            float flake = snowflake(drop, size, 18.0) * amount;
            return flake;
        }

        void main() {
            vec2 uv = v_uv;
            vec2 p = (gl_FragCoord.xy / u_resolution) * 2.0 - 1.0;
            p.x *= u_resolution.x / u_resolution.y;
            float t = u_time * mix(1.0, 0.5, u_reduce);

            // Sky gradient with subtle fog
            vec3 skyTop = vec3(0.06, 0.12, 0.20);
            vec3 skyMid = vec3(0.12, 0.20, 0.30);
            vec3 skyFog = vec3(0.78, 0.84, 0.90);
            float horizon = smoothstep(-0.2, 0.6, uv.y);
            vec3 sky = mix(skyFog, mix(skyMid, skyTop, horizon), 0.55);

            // Mountain parallax layers
            float fogFar = smoothstep(0.2, 0.85, uv.y);
            vec3 col = sky;
            col = mix(col, mountainLayer(uv + vec2(0.0, -0.05), t * 0.6, 1.6, 0.35, fogFar * 1.2), 0.55);
            col = mix(col, mountainLayer(uv + vec2(0.05, -0.02), t * 0.8, 2.4, 0.45, fogFar * 0.8), 0.65);
            col = mix(col, mountainLayer(uv + vec2(-0.08, 0.02), t, 3.4, 0.55, fogFar * 0.5), 0.8);

            // Snow layers
            float snowFar = layeredSnow(uv * vec2(1.1, 1.0), t * 0.35, 18.0, -0.06, -0.07, 0.045, 0.55, 0.2);
            float snowMid = layeredSnow(uv * vec2(1.0, 1.0), t * 0.5, 12.0, -0.12, -0.1, 0.06, 0.75, 0.35);
            float snowNear = layeredSnow(uv * vec2(0.9, 1.0), t * 0.7, 8.0, -0.18, -0.14, 0.09, 1.05, 0.55);

            float snowMix = snowFar + snowMid + snowNear;
            vec3 snowColor = vec3(0.95, 0.97, 1.0);
            col = mix(col, snowColor, clamp(snowMix, 0.0, 1.0));

            // Fog and vignette
            float fog = smoothstep(0.2, 0.95, uv.y);
            col = mix(col, skyFog, fog * 0.55);
            float vign = smoothstep(1.2, 0.4, length(p));
            col *= mix(0.85, 1.0, vign);

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

        const flakes = [];
        for (let i = 0; i < 180; i++) {
            flakes.push({
                x: Math.random() * w,
                y: Math.random() * h,
                r: 1 + Math.random() * 2,
                s: 0.5 + Math.random() * 1.5
            });
        }

        function draw2d() {
            ctx.clearRect(0, 0, w, h);
            const grd = ctx.createLinearGradient(0, 0, 0, h);
            grd.addColorStop(0, '#0b1a2c');
            grd.addColorStop(0.6, '#15263a');
            grd.addColorStop(1, '#e1e8f0');
            ctx.fillStyle = grd;
            ctx.fillRect(0, 0, w, h);

            ctx.fillStyle = '#a0b3c7';
            ctx.beginPath();
            ctx.moveTo(0, h * 0.65);
            ctx.lineTo(w * 0.3, h * 0.5);
            ctx.lineTo(w * 0.6, h * 0.7);
            ctx.lineTo(w, h * 0.55);
            ctx.lineTo(w, h);
            ctx.lineTo(0, h);
            ctx.closePath();
            ctx.fill();

            ctx.fillStyle = 'rgba(255,255,255,0.9)';
            for (const f of flakes) {
                ctx.beginPath();
                ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
                ctx.fill();
                f.y += f.s * 1.5;
                f.x += Math.sin(f.y * 0.01) * 0.5;
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
