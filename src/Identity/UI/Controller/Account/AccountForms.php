<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\UI\Form\CodeType;
use Identity\UI\Form\NewPasswordType;
use Identity\UI\Form\PasskeyType;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\ActionForm;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The forms of the account security page, built the same way for rendering and for handling (same names, same
 * CSRF token ids). They post without Turbo (`data-turbo="false"`): a refusal re-renders the form alone.
 */
final readonly class AccountForms
{
    public const string FRAME = 'account-security';
    public const string TEMPLATE = 'identity/account/action_form.html.twig';
    public const string PASSWORD = 'change_password';
    public const string TOTP_START = 'totp_start';
    public const string TOTP_CONFIRM = 'totp_confirm';
    public const string TOTP_DISABLE = 'totp_disable';
    public const string BACKUP_CODES = 'backup_codes';
    public const string PASSKEY_REGISTER = 'passkey_register';

    public function __construct(private FormFactoryInterface $forms)
    {
    }

    /**
     * @return FormInterface<array{password: string}|null>
     */
    public function password(): FormInterface
    {
        return $this->forms->createNamed(self::PASSWORD, NewPasswordType::class, null, ['csrf_token_id' => self::PASSWORD]);
    }

    /**
     * @return FormInterface<array{code: string}|null>
     */
    public function totpConfirm(): FormInterface
    {
        return $this->forms->createNamed(self::TOTP_CONFIRM, CodeType::class, null, ['csrf_token_id' => self::TOTP_CONFIRM]);
    }

    /**
     * @return FormInterface<array{name: string, response: string}|null>
     */
    public function passkey(): FormInterface
    {
        return $this->forms->createNamed(self::PASSKEY_REGISTER, PasskeyType::class, null, ['csrf_token_id' => self::PASSKEY_REGISTER]);
    }

    /**
     * The name (and CSRF token id) of the button revoking one passkey.
     */
    public static function passkeyRevoke(string $passkeyId): string
    {
        return 'passkey_revoke_'.$passkeyId;
    }

    /**
     * @return FormInterface<null>
     */
    public function button(string $name): FormInterface
    {
        return $this->forms->createNamed($name, ActionButtonType::class, null, ['csrf_token_id' => $name]);
    }

    /**
     * @param FormInterface<covariant mixed> $form
     */
    public function action(FormInterface $form, string $titleKey, string $routeName): ActionForm
    {
        return new ActionForm($form, self::FRAME, self::TEMPLATE, ['title_key' => $titleKey, 'action_route' => $routeName]);
    }
}
