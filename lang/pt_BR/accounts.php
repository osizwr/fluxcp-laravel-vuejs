<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account credential messages — Brazilian Portuguese
|--------------------------------------------------------------------------
|
| Mirrors lang/en/accounts.php, including its silences. Several of these say
| the same thing whether or not an account matched -- that is what stops the
| forms confirming which addresses are registered, and it must survive
| translation. Read the notes in the English file before changing any of them.
|
*/

return [

    'registration' => [
        'disabled' => 'Não estamos aceitando novas contas no momento.',
        'created' => 'Sua conta está pronta. Você já pode entrar.',
        'confirmation_sent' => 'Sua conta foi criada. Verifique :email para o link que a ativa.',
    ],

    'confirmation' => [
        'invalid' => 'Esse link de confirmação não é válido. Ele pode já ter sido usado ou ter expirado.',
        'confirmed' => 'Sua conta está confirmada. Você já pode entrar.',
        'resent' => 'Se essa conta estiver aguardando confirmação, um novo link está a caminho do e-mail dela.',
    ],

    'reset' => [
        'requested' => 'Se esses dados corresponderem a uma conta, um link para escolher uma nova senha está a caminho do e-mail dela.',
        'invalid' => 'Esse link de redefinição não é válido. Ele pode já ter sido usado ou ter expirado.',
        'complete' => 'Sua senha foi alterada. Você já pode entrar com ela.',
        'not_permitted' => 'A senha desta conta não pode ser redefinida por e-mail. Fale com um administrador.',
    ],

    'password' => [
        'changed' => 'Sua senha foi alterada.',
        'current_incorrect' => 'Essa não é a sua senha atual.',
        'same_as_current' => 'Escolha uma senha diferente da atual.',
    ],

    'email' => [
        'changed' => 'Seu endereço de e-mail foi atualizado.',
        'confirmation_sent' => 'Verifique :email para o link que confirma a alteração. Seu endereço continua o mesmo até você segui-lo.',
        'same_as_current' => 'Esse já é o seu endereço de e-mail.',
        'in_use' => 'Esse endereço de e-mail já está em uso.',
        'invalid' => 'Esse link de confirmação não é válido. Ele pode já ter sido usado ou ter expirado.',
        'confirmed' => 'Seu endereço de e-mail foi atualizado.',
    ],

    'captcha' => [
        'incorrect' => 'Os caracteres digitados não corresponderam à imagem.',
        'expired' => 'A imagem de segurança expirou. Tente a nova.',
        'unavailable' => 'Não foi possível concluir a verificação de segurança. Tente novamente.',
    ],

];
