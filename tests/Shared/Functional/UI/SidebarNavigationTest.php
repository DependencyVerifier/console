<?php

declare(strict_types=1);

namespace Tests\Shared\Functional\UI;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\IdentityWebTestCase;

/**
 * The sidebar highlights the entry of the page being browsed and groups the super-admin entries under
 * an "Administration" label.
 */
final class SidebarNavigationTest extends IdentityWebTestCase
{
    private const string ADMIN_LABEL = '[data-slot="sidebar-group-label"]';

    #[Test]
    public function it_should_mark_the_teams_entry_as_active_on_the_teams_pages(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        foreach (['/admin/teams', '/admin/settings/quotas'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorExists('a[aria-current="page"][href="/admin/teams"]', $url);
            self::assertSelectorNotExists('a[aria-current="page"][href="/admin/users"]', $url);
        }
    }

    #[Test]
    public function it_should_mark_the_users_entry_as_active_on_the_users_page(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[aria-current="page"][href="/admin/users"]');
        self::assertSelectorNotExists('a[aria-current="page"][href="/admin/teams"]');
    }

    #[Test]
    public function it_should_group_the_super_admin_entries_under_the_administration_label(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/teams');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(self::ADMIN_LABEL, 'Administration');
        self::assertSelectorExists('a[href="/admin/users"]');
        self::assertSelectorExists('a[href="/admin/teams"]');
    }

    #[Test]
    public function it_should_hide_the_administration_category_from_regular_users(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::ADMIN_LABEL);
        self::assertSelectorNotExists('a[href="/admin/users"]');
        self::assertSelectorNotExists('a[href="/admin/teams"]');
    }
}
