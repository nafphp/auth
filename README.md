<div align="center">

![NAF](assets/naf-logo-small-square.png)

[![NAF Auth Plugin](https://github.com/nafphp/auth/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/auth/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/auth

> **Log people in, and check what they may do — with your own user model.**

```php
auth()->authenticate(new PasswordCredentials($username, $password));   // sign in
auth()->user();                                                   // your own User object
auth()->can('posts.edit');                                        // bool, guests included
auth()->requireRole('admin');                                     // or a 403 leaves the controller
```

> 🧩 Part of the official NAF plugin collection.
> Install it when you need logins, and nothing else.

## Documentation

**[Authentication and permissions →](https://nafphp.github.io/docs/auth/)**

Everything about this package — what it does, how it is configured and what it needs — lives
in the [NAF documentation](https://nafphp.github.io/docs/). Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/auth
```

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).
