/*
 * Aurelia Studio: приём заявок (Cloudflare Worker).
 * Заявка уходит в Telegram и на почту. Секреты хранятся в настройках Cloudflare, а не в коде:
 *   TG_TOKEN        токен бота от @BotFather
 *   TG_CHAT_ID      ваш ID от @userinfobot
 *   WEB3FORMS_KEY   ключ с web3forms.com (для писем на почту), необязательно
 *   ALLOWED_ORIGIN  адрес сайта, например https://ваше-имя.github.io, необязательно
 */
const json = (data, status, origin) =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': origin || '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Cache-Control': 'no-store',
    },
  });

const clean = (v, max) =>
  String(typeof v === 'string' ? v : '')
    .replace(/[\u0000-\u001F\u007F]+/g, ' ')
    .replace(/<[^>]*>/g, '')
    .trim()
    .slice(0, max);

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export default {
  async fetch(request, env) {
    const origin = env.ALLOWED_ORIGIN || '*';
    if (request.method === 'OPTIONS') return json({}, 204, origin);

    const tgReady = !!(env.TG_TOKEN && env.TG_CHAT_ID);
    const mailReady = !!env.WEB3FORMS_KEY;

    if (request.method === 'GET') return json({ ok: true, configured: tgReady || mailReady }, 200, origin);
    if (request.method !== 'POST') return json({ ok: false }, 405, origin);

    if (env.ALLOWED_ORIGIN && request.headers.get('Origin') !== env.ALLOWED_ORIGIN)
      return json({ ok: false, error: 'forbidden' }, 403, origin);

    let body;
    try {
      body = JSON.parse((await request.text()).slice(0, 8192));
    } catch (e) {
      return json({ ok: false, error: 'bad_request' }, 400, origin);
    }
    if (!body || typeof body !== 'object') return json({ ok: false, error: 'bad_request' }, 400, origin);
    if (body.website) return json({ ok: true }, 200, origin); // ловушка для роботов

    const name = clean(body.name, 60);
    const digits = clean(body.phone, 30).replace(/\D+/g, '');
    if (name.length < 2 || !/^375\d{9}$/.test(digits)) return json({ ok: false, error: 'invalid' }, 422, origin);

    const now = new Date().toLocaleString('ru-RU', {
      timeZone: 'Europe/Minsk', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
    const lines = ['Имя: ' + name, 'Телефон: +' + digits];
    let title;
    if (body.type === 'booking') {
      title = 'Новая онлайн-запись с сайта';
      const price = clean(body.price, 40);
      lines.push('Мастер: ' + clean(body.master, 80));
      lines.push('Процедура: ' + clean(body.service, 120) + (price ? ' (' + price + ')' : ''));
      lines.push('День: ' + clean(body.date, 40));
      lines.push('Время: ' + clean(body.slot, 20));
    } else {
      title = 'Новая заявка на звонок с сайта';
      const topic = clean(body.topic, 30);
      if (topic === 'certificate') lines.push('Тема: подарочный сертификат');
      if (topic === 'discount') lines.push('Тема: скидка 10% на первое посещение');
      lines.push('Удобное время для звонка: ' + clean(body.time, 60));
      lines.push('Согласие на рассылку: ' + (body.ads ? 'да' : 'нет'));
    }
    lines.push('Отправлено: ' + now + ' (Минск)');

    let tgOk = false;
    let mailOk = false;

    if (tgReady) {
      try {
        const r = await fetch('https://api.telegram.org/bot' + env.TG_TOKEN + '/sendMessage', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: env.TG_CHAT_ID,
            text: '<b>' + esc(title) + '</b>\n\n' + esc(lines.join('\n')),
            parse_mode: 'HTML',
          }),
        });
        const j = await r.json();
        tgOk = !!j.ok;
      } catch (e) {}
    }

    if (mailReady) {
      try {
        const r = await fetch('https://api.web3forms.com/submit', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            access_key: env.WEB3FORMS_KEY,
            subject: title,
            from_name: 'Aurelia Studio',
            message: lines.join('\n'),
          }),
        });
        const j = await r.json();
        mailOk = !!j.success;
      } catch (e) {}
    }

    if (tgOk || mailOk) return json({ ok: true, telegram: tgOk, email: mailOk }, 200, origin);
    return json({ ok: false, error: 'delivery_failed' }, 502, origin);
  },
};
