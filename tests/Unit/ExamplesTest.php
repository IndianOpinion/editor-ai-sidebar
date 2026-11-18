<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;

/**
 * The example providers, run end to end through the REST contract
 * (tests T62 to T66, and the word-count example).
 */
final class ExamplesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/examples/canned-providers/canned-providers.php';
        require_once dirname(__DIR__, 2) . '/examples/word-count/word-count.php';
        add_filter('editor_ai_sidebar_actions', 'editor_ai_sidebar_canned_actions');
        add_filter('editor_ai_sidebar_actions', 'editor_ai_sidebar_word_count_register');
        editor_ai_sidebar_canned_register_meta();
        $this->plugin->registry()->reset();
    }

    public function testAllExamplesRegisterCleanly(): void
    {
        self::assertSame([], editor_ai_sidebar_rejected_definitions());
        $ids = array_keys(editor_ai_sidebar_actions($this->post()));
        sort($ids);
        self::assertSame(
            ['canned/alt', 'canned/headlines', 'canned/meta', 'canned/summary', 'canned/translation', 'example/word-count'],
            $ids
        );
    }

    public function testWordCountEndToEnd(): void
    {
        $post = $this->post();
        $text = 'नमस्ते दुनिया. It’s a well-known test, with 9 words.';
        $run = $this->runAction('example/word-count', $post, ['text' => $text]);
        self::assertSame(200, $run->get_status());
        $envelope = $run->get_data();
        self::assertSame('9 words, about 1 minute to read.', $envelope['items'][0]['value']);
        $apply = $this->applyItem($envelope, 'count');
        self::assertSame(200, $apply->get_status());
        self::assertSame('copy', $apply->get_data()['operation']);
        self::assertSame([], \WpStub::$meta[$post] ?? [], 'writes nothing');

        $empty = $this->runAction('example/word-count', $post, ['text' => ''])->get_data();
        self::assertSame('0 words, about 1 minute to read.', $empty['items'][0]['value']);
        self::assertSame('The post has no text yet.', $empty['notes'][0]['text']);
    }

    public function testT62SummaryInsertsListAtStart(): void
    {
        $post = $this->post();
        $envelope = $this->runAction('canned/summary', $post, ['title' => 'T', 'text' => 'B'])->get_data();
        self::assertSame('list', $envelope['items'][0]['type']);
        $body = $this->applyItem($envelope, 'summary')->get_data();
        self::assertSame('insert_blocks', $body['operation']);
        self::assertSame('start', $body['position']);
        self::assertCount(3, $body['value']);
    }

    public function testT63HeadlinesChoicesEditable(): void
    {
        $post = $this->post();
        $envelope = $this->runAction('canned/headlines', $post, ['title' => 'T', 'text' => 'B', 'excerpt' => ''])->get_data();
        self::assertSame('choices', $envelope['layout']);
        self::assertCount(3, $envelope['items']);
        self::assertSame('Clarity', $envelope['items'][0]['score_label']);
        self::assertSame('warning', $envelope['items'][1]['notes'][0]['level']);
        $this->assertError($this->applyItem($envelope, 'b', ['value' => str_repeat('x', 121)]), 422, 'headline_too_long');
        $body = $this->applyItem($envelope, 'b', ['value' => 'An edited headline'])->get_data();
        self::assertSame('set_title', $body['operation']);
        self::assertSame('An edited headline', $body['value']);
    }

    public function testT64MetaServerModeSyncsBothKeys(): void
    {
        $post = $this->post();
        $envelope = $this->runAction('canned/meta', $post, ['title' => 'T', 'text' => 'B'])->get_data();
        $body = $this->applyItem($envelope, 'meta')->get_data();
        self::assertSame('server', $body['mode']);
        self::assertSame('A meta title for the post', get_post_meta($post, 'canned_meta_title', true));
        self::assertEquals((object) [
            'meta' => (object) [
                'canned_meta_title' => 'A meta title for the post',
                'canned_meta_description' => 'A one-sentence description of the post for search results.',
            ],
        ], $body['sync']);
    }

    public function testT65AltSetsTheSelectedImageOnly(): void
    {
        $post = $this->post();
        $selection = ['name' => 'core/image', 'client_id' => 'img-2', 'attributes' => ['id' => 12, 'url' => 'https://example.test/a.jpg', 'alt' => '']];
        $envelope = $this->runAction('canned/alt', $post, ['title' => 'T', 'selection' => $selection])->get_data();
        $body = $this->applyItem($envelope, 'alt')->get_data();
        self::assertSame('set_block_attribute', $body['operation']);
        self::assertSame('alt', $body['attribute']);
        self::assertSame([], \WpStub::$meta[12] ?? [], 'the attachment is untouched');
    }

    public function testT66TranslationQueuedDraftLinkStatusUnchanged(): void
    {
        $post = $this->post('post', 'publish');
        $start = $this->runAction('canned/translation', $post, ['title' => 'T', 'content' => 'C', 'language' => 'en-GB']);
        self::assertSame(202, $start->get_status());
        $envelope = $this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $start->get_data()['job_id'])->get_data();
        self::assertSame('fr', $envelope['items'][0]['lang']);
        $body = $this->applyItem($envelope, 'fr')->get_data();
        self::assertSame('Draft created. The current post is unchanged.', $body['message']);
        self::assertStringContainsString('post.php?post=', $body['links'][0]['url']);
        self::assertSame('publish', get_post_status($post));
        $draftId = (int) preg_replace('/\D+/', '', (string) parse_url($body['links'][0]['url'], PHP_URL_QUERY));
        self::assertSame('draft', get_post_status($draftId));
        self::assertSame([], $this->firedOf('editor_ai_sidebar_contract_violation'));
    }
}
