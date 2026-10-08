<!doctype html>
<html lang="en" dir="ltr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Isolated Phase 6A Staging</title></head>
<body>
<main>
    <h1>Isolated Phase 6A Staging</h1>
    <p>This authentication page is available only on the socket-isolated staging environment.</p>
    <form method="post" action="/__phase6a/login">
        @csrf
        <label>Email <input type="email" name="email" required autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
