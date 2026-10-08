<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages — Brazilian Portuguese
|--------------------------------------------------------------------------
|
| Mirrors lang/en/auth.php. Read the notes there before changing any of these:
| the vagueness of `invalid_credentials` is deliberate and must survive
| translation, because a message that names the field that was wrong tells
| somebody guessing whether the account exists.
|
*/

return [

    'failure' => [
        'unexpected' => 'Algo deu errado ao conectar você. Tente novamente.',
        'unknown_server' => 'Esse servidor de jogo não está disponível.',
        'invalid_credentials' => 'Os dados de acesso não estão corretos.',
        'temporarily_banned' => 'Esta conta está suspensa. Verifique seu e-mail para saber o motivo e a data de término.',
        'permanently_banned' => 'Esta conta foi banida permanentemente.',
        'ip_banned' => 'Sua conexão não tem permissão para entrar.',
        'invalid_security_code' => 'O código de segurança não estava correto.',
        'pending_confirmation' => 'Esta conta ainda precisa ser confirmada. Verifique seu e-mail para o link de confirmação.',
    ],

    'failed' => 'Os dados de acesso não estão corretos.',
    'password' => 'A senha não está correta.',
    'throttle' => 'Tentativas demais. Tente novamente em :seconds segundos.',

];
