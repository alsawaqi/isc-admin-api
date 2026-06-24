<?php

namespace Tests\Unit\Reviews;

use App\Support\Reviews\ProductReviewModeration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductReviewModerationTest extends TestCase
{
    public function test_it_builds_a_moderation_snapshot_for_visible_and_hidden_statuses(): void
    {
        $approved = ProductReviewModeration::snapshot('approved', 'Looks good', 12);

        $this->assertSame('approved', $approved['Status']);
        $this->assertSame('Looks good', $approved['Moderator_Note']);
        $this->assertSame(12, $approved['Moderated_By']);
        $this->assertArrayHasKey('Moderated_At', $approved);

        $pending = ProductReviewModeration::snapshot('pending', null, 12);

        $this->assertSame('pending', $pending['Status']);
        $this->assertNull($pending['Moderator_Note']);
        $this->assertNull($pending['Moderated_At']);
    }

    public function test_it_rejects_unknown_moderation_statuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductReviewModeration::snapshot('archived', null, 12);
    }

    public function test_it_normalizes_reply_metadata(): void
    {
        $reply = ProductReviewModeration::replySnapshot('admin', 7, ' Thanks for the feedback. ');

        $this->assertSame([
            'Reply_Type' => 'admin',
            'User_Id' => 7,
            'Body' => 'Thanks for the feedback.',
            'Status' => 'approved',
        ], $reply);
    }
}
