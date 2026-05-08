<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Halaman Tidak Ditemukan — KitaCatat</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Sora', sans-serif;
            background: #0a0f0d;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
        }
        .wrap { max-width: 420px; }
        .code {
            font-size: 96px;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, #16a34a, #6ee7b7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 10px; }
        p { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 28px; }
        .btn-group { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 11px 22px; border-radius: 9px;
            font-size: 13px; font-weight: 700;
            text-decoration: none; transition: all .15s;
        }
        .btn-green { background: #16a34a; color: #fff; }
        .btn-green:hover { background: #15803d; }
        .btn-ghost { background: rgba(255,255,255,.07); color: #d1d5db; border: 1px solid rgba(255,255,255,.1); }
        .btn-ghost:hover { background: rgba(255,255,255,.12); color: #fff; }
        .emoji { font-size: 48px; margin-bottom: 16px; display: block; }
    </style>
</head>
<body>
<div class="wrap">
    <span class="emoji">💬</span>
    <div class="code">404</div>
    <h1>Halaman tidak ditemukan</h1>
    <p>Halaman yang Anda cari tidak ada atau sudah dipindahkan. Coba kembali ke dashboard atau halaman utama.</p>
    <div class="btn-group">
        <a href="/dashboard/" class="btn btn-green">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="/" class="btn btn-ghost">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Beranda
        </a>
    </div>
</div>
</body>
</html>
