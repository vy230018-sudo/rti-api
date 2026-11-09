<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_requires_admin_approval_before_visibility(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $token = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/news', [
                'tittle' => 'Breaking News',
                'sub_tittle' => 'First subtitle',
                'description' => 'Pending approval content',
                'report_type' => 'field',
                'media_type' => 1,
            ]);

        $newsId = $createResponse
            ->assertCreated()
            ->assertJsonPath('news.status', 'pending')
            ->json('news.id');

        $this->getJson('/news')
            ->assertOk()
            ->assertJsonCount(0, 'news');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/my-news')
            ->assertOk()
            ->assertJsonCount(0, 'news');

        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $adminToken = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/admin-rti/news/'.$newsId.'/status', [
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('news.status', 'approved');

        $this->getJson('/news')
            ->assertOk()
            ->assertJsonCount(1, 'news');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/my-news')
            ->assertOk()
            ->assertJsonCount(1, 'news');
    }

    public function test_soft_deleted_news_is_hidden_from_public_and_admin_can_filter_deleted(): void
    {
        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $news = News::query()->create([
            'user_id' => '10',
            'tittle' => 'Approved News',
            'sub_tittle' => 'Subtitle',
            'description' => 'Visible before delete',
            'report_type' => 'desk',
            'media_type' => 1,
            'status' => 'approved',
            'is_deleted' => 0,
        ]);

        $adminToken = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->deleteJson('/admin-rti/news/'.$news->id)
            ->assertOk();

        $this->assertDatabaseHas('news', [
            'id' => $news->id,
            'is_deleted' => 1,
        ]);

        $this->getJson('/news')
            ->assertOk()
            ->assertJsonCount(0, 'news');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/admin-rti/news')
            ->assertOk()
            ->assertJsonCount(0, 'news');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/admin-rti/news?include_deleted=1')
            ->assertOk()
            ->assertJsonCount(1, 'news');
    }
}
