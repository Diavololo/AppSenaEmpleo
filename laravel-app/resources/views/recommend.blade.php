<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recomendaciones</title>
    <style>
        :root { font-family: Arial, sans-serif; color: #0f172a; background:#f8fafc; }
        body { margin: 0; padding: 24px; display:flex; justify-content:center; }
        .card { background:#fff; padding:24px; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,0.08); width: min(900px, 100%); }
        h1 { margin-top:0; }
        label { display:block; margin:12px 0 4px; font-weight:600; }
        input, select { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; }
        button { margin-top:16px; padding:12px 16px; background:#2563eb; color:#fff; border:none; border-radius:8px; cursor:pointer; }
        button:disabled { opacity:.6; cursor:not-allowed; }
        pre { background:#0f172a; color:#e2e8f0; padding:16px; border-radius:8px; overflow:auto; }
        .error { color:#b91c1c; margin-top:8px; }
        .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
        .chip { background:#e2e8f0; padding:4px 8px; border-radius:999px; font-size:12px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Recomendador de vacantes</h1>
        <p>Ingresa el email de un candidato para obtener las recomendaciones calculadas con embeddings y OpenAI.</p>

        <label for="email">Email del candidato</label>
        <input id="email" type="email" placeholder="usuario@correo.com">

        <label for="limit">Límite de resultados</label>
        <select id="limit">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="20">20</option>
        </select>

        <label><input type="checkbox" id="explain"> Incluir breve explicación</label>

        <button id="btn">Consultar</button>
        <div id="msg" class="error"></div>

        <div id="results"></div>
    </div>

    <script>
        const btn = document.getElementById('btn');
        const msg = document.getElementById('msg');
        const results = document.getElementById('results');

        btn.onclick = async () => {
            msg.textContent = '';
            results.innerHTML = '';
            const email = document.getElementById('email').value.trim();
            const limit = document.getElementById('limit').value;
            const explain = document.getElementById('explain').checked;
            if (!email) { msg.textContent = 'Email es obligatorio'; return; }
            btn.disabled = true;
            btn.textContent = 'Consultando...';
            try {
                const params = new URLSearchParams({ email, limit, explain });
                const res = await fetch(`/api/recomendaciones?${params.toString()}`);
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Error en la solicitud');
                render(data);
            } catch (e) {
                msg.textContent = e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Consultar';
            }
        };

        function render(data) {
            if (!data.recommendations || !data.recommendations.length) {
                results.innerHTML = '<p>No hay recomendaciones.</p>';
                return;
            }
            const html = data.recommendations.map(rec => `
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin-top:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <strong>${rec.titulo || 'Vacante'}</strong>
                        <span style="font-size:12px; color:#475569;">Score: ${rec.score}</span>
                    </div>
                    <div style="color:#475569; font-size:14px; margin-top:4px;">
                        ${rec.ciudad || 'Ciudad N/D'} · ${rec.area || 'Área N/D'} · ${rec.nivel || 'Nivel N/D'} · ${rec.modalidad || 'Modalidad N/D'}
                    </div>
                    ${rec.resumen ? `<p style="margin:8px 0;">${rec.resumen}</p>` : ''}
                    ${rec.requisitos ? `<p style="margin:8px 0; color:#475569;">Requisitos: ${rec.requisitos}</p>` : ''}
                    ${rec.etiquetas && rec.etiquetas.length ? `<div class="chips">${rec.etiquetas.map(t => `<span class="chip">${t}</span>`).join('')}</div>` : ''}
                    ${rec.explicacion ? `<p style="margin:8px 0; color:#0f172a;">${rec.explicacion}</p>` : ''}
                </div>
            `).join('');
            results.innerHTML = html;
        }
    </script>
</body>
</html>
