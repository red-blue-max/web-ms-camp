/**
 * Ретранслятор заявок в Telegram (Cloudflare Worker).
 *
 * Зачем он нужен: хостинг Timeweb не пропускает соединения к api.telegram.org.
 * Сайт отправляет заявку сюда, а Worker пересылает её боту.
 *
 * Токен бота хранится в секретах Cloudflare и в коде не появляется.
 * Как развернуть — см. README.md, раздел «Telegram через ретранслятор».
 */
export default {
  async fetch(request, env) {
    if (request.method !== 'POST') {
      return new Response('MosSwim relay', { status: 200 });
    }

    let data;
    try {
      data = await request.json();
    } catch {
      return json({ ok: false, error: 'bad_json' }, 400);
    }

    // Простая проверка «свой — чужой»: общий секрет сайта и воркера
    if (!env.RELAY_SECRET || data.secret !== env.RELAY_SECRET) {
      return json({ ok: false, error: 'forbidden' }, 403);
    }

    const text = typeof data.text === 'string' ? data.text.slice(0, 4000) : '';
    if (!text) return json({ ok: false, error: 'empty' }, 400);

    const res = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: env.TELEGRAM_CHAT_ID,
        text,
        parse_mode: 'HTML',
        disable_web_page_preview: true,
      }),
    });

    const body = await res.text();
    if (!res.ok) {
      return json({ ok: false, error: 'telegram', status: res.status, body: body.slice(0, 300) }, 502);
    }
    return json({ ok: true });
  },
};

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}
