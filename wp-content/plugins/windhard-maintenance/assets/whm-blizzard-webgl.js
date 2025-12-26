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
            for (int i = 0; i < 4; i++) {
                f += w * noise(p);
                p *= 2.3;
                w *= 0.5;
            }
            return f;
        }

        vec2 windField(vec2 uv, float t) {
            float baseAngle = 1.1;
            float baseMag = 0.9;
            float gustNoise = fbm(vec2(t * 0.05, 0.3));
            float gust = smoothstep(0.2, 0.8, gustNoise) * 0.8;
            float angle = baseAngle + noise(vec2(t * 0.05, uv.y * 0.8)) * 0.8;
            float mag = baseMag + gust + noise(vec2(t * 0.1, uv.x * 0.7)) * 0.3;
            return vec2(cos(angle), sin(angle)) * mag;
        }

        float snowShape(vec2 p, float aspect) {
            p.x *= aspect;
            vec2 ap = abs(p);
            float lens = max(ap.x * 0.9 + ap.y * 0.6, length(p) * 0.8);
            return smoothstep(0.18, 0.0, lens);
        }

        float streakSample(vec2 uv, vec2 dir, float scale, float speed, float size) {
            vec2 gv = fract(uv * scale + dir * speed * u_time) - 0.5;
            vec2 id = floor(uv * scale + dir * speed * u_time);
            vec2 offset = (hash(id + 1.7) - 0.5) * 0.6;
            float aspect = mix(0.6, 1.4, hash(id + 2.5));
            vec2 p = gv + offset;
            float shape = snowShape(p / size, aspect);
            return shape;
        }

        float layeredSnow(vec2 uv, vec2 wind, float scale, float speed, float size, int streaks) {
            float accum = 0.0;
            vec2 dir = normalize(wind + vec2(0.001, 0.0));
            float stepLen = 0.015 * length(wind);
            for (int i = 0; i < 5; i++) {
                if (i >= streaks) break;
                float w = exp(-float(i) * 0.7);
                vec2 offset = dir * stepLen * float(i);
                accum += w * streakSample(uv + offset, wind, scale, speed, size);
            }
            return accum;
        }

        void main() {
            vec2 uv = v_uv;
            vec2 p = uv;
            p.y = 1.0 - p.y;
            vec2 wind = windField(p, u_time);

            vec3 bgTop = vec3(0.05, 0.09, 0.14);
            vec3 bgBot = vec3(0.04, 0.08, 0.12);
            vec3 bg = mix(bgTop, bgBot, p.y);

            vec2 fogUV = p * vec2(1.0, 1.2) + wind * (u_time * 0.02);
            float fogBase = fbm(fogUV * 1.3);
            float fogAmount = smoothstep(0.2, 0.9, fogBase) * smoothstep(0.0, 1.0, 1.0 - p.y * 0.8);
            vec3 fogColor = vec3(0.75, 0.82, 0.9);

            float farLayer = layeredSnow(p * 0.9, wind * 0.4, 14.0, 0.45, 0.8, 1);
            float midLayer = layeredSnow(p * 1.3, wind * 0.8, 22.0, 0.7, 0.65, 2);
            float nearLayer = layeredSnow(p * 1.8, wind * 1.2, 32.0, 1.0, 0.55, 5);

            float snowAlpha = clamp(farLayer * 0.6 + midLayer * 0.9 + nearLayer * 1.2, 0.0, 1.8);
            vec3 snowColor = vec3(0.92, 0.97, 1.0);

            float vignette = smoothstep(1.2, 0.35, length(p - 0.5));

            vec3 color = mix(bg, fogColor, fogAmount * 0.35);
            color = mix(color, snowColor, snowAlpha * 0.75);
            color *= vignette;

            float dither = (hash(gl_FragCoord.xy / u_resolution.xy + u_seed) - 0.5) / 255.0;
            color += dither;

            gl_FragColor = vec4(color, 1.0);
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

    function render(now) {
        resize();
        const t = (now - start) * 0.001;

        gl.uniform1f(timeLoc, t);
        gl.uniform2f(resolutionLoc, gl.canvas.width, gl.canvas.height);
        gl.uniform1f(seedLoc, seed);

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
