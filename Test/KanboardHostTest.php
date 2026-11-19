<?php
require_once 'tests/units/Base.php';

use ChatParts\Model\Participant;
use ChatParts\View\Receipt;
use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;

class KanboardHostTest extends Base
{
    private KanboardHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../vendor-chatparts/autoload.php';
        $this->container['configModel']->save(['application_url' => 'http://kb.example/']);
        $this->container['memoryCache']->flush();
        $this->host = new KanboardHost($this->container);
    }

    public function testMarkdownDropsExternalImages(): void
    {
        $html = $this->host->markdown(
            "![ext](https://evil.example/a.png) ![rel](/files/b.png) ![proto](//evil.example/c.png)\n\n"
            ."![same](http://kb.example/d.png) ![relative](img/e.png) ![otherport](http://kb.example:9000/f.png)\n\n"
            .'![bs1](/\evil.example/g.png) ![bs2](http://evil.example\@kb.example/h.png) ![at](http://evil.example@kb.example/i.png)'."\n\n"
            .'![data](data:image/png;base64,iVBORw0KGgo=) ![js](javascript:alert(1)) ![tab](/files/%09x.png)'
        );

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString(':9000', $html, 'same host on another port is another origin');
        $this->assertStringNotContainsString('data:', $html);
        $this->assertStringNotContainsString('javascript', strtolower($html));
        $this->assertStringNotContainsString('e.png', $html, 'only paths from the site root, not page-relative ones');
        $this->assertStringContainsString('<img src="/files/b.png"', $html);
        $this->assertStringContainsString('<img src="http://kb.example/d.png"', $html);
        $this->assertStringContainsString('<img src="/files/%09x.png"', $html, 'a percent-encoded tab is just a path');
        $this->assertSame(3, substr_count($html, '<img'));
    }

    public function testMarkdownDropsUnsafeLinksToPlainText(): void
    {
        $html = $this->host->markdown(
            '[a](javascript:alert(1)) [b](JaVaScRiPt:alert(2)) [c](javascript&#58;alert(3)) [d](data:text/html;base64,PHNjcmlwdD4=) '
            .'[e](vbscript:x) [f](ftp://files.example/x) [g](/\evil.example/) [ok1](https://example.org/x) [ok2](mailto:ada@example.org) [ok3](/task/3) [ok4](?q=1)'
        );

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $text) {
            $this->assertStringNotContainsString('>'.$text.'</a>', $html, "link $text must become plain text");
            $this->assertStringContainsString(' '.$text.' ', ' '.strip_tags($html).' ', "the text of link $text stays");
        }
        $this->assertDoesNotMatchRegularExpression('~href="\s*(javascript|vbscript|data)~i', html_entity_decode(html_entity_decode($html)));
        $this->assertStringContainsString('<a href="https://example.org/x">ok1</a>', $html);
        $this->assertStringContainsString('<a href="mailto:ada@example.org">ok2</a>', $html);
        $this->assertStringContainsString('<a href="/task/3">ok3</a>', $html);
        $this->assertStringContainsString('<a href="?q=1">ok4</a>', $html);
    }

    public function testMarkdownKeepsImagesFromTheRequestHost(): void
    {
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost:8081'], [], [], [], []);
        $html = (new KanboardHost($this->container))->markdown('![a](http://localhost:8081/x.png) ![b](http://localhost/y.png)');

        $this->assertStringContainsString('<img src="http://localhost:8081/x.png"', $html);
        $this->assertStringNotContainsString('y.png', $html);
    }

    public function testMarkdownEscapesRawHtml(): void
    {
        $html = $this->host->markdown('<script>alert(1)</script> <img src="https://evil.example/x.png">');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testMarkdownLinksTaskRefs(): void
    {
        $html = $this->host->markdown('See #12 please');

        // No routes in unit tests, so Kanboard builds the query-string form.
        $this->assertStringContainsString('<a href="?controller=TaskViewController&amp;action=show&amp;task_id=12">#12</a>', $html);
    }

    public function testTranslatedArgumentIsEscapedOnceInAComponent(): void
    {
        $this->assertSame('answered for <b>&', $this->host->t('answered for %s', '<b>&'), 't() leaves escaping to the kit');

        $html = (string) new Receipt($this->host, ['bot' => 'project-bot', 'asked_by' => ['name' => '<b>&']]);

        $this->assertStringContainsString('answered for &lt;b&gt;&amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function testViewerIdIsTheLoggedInUsersParticipantId(): void
    {
        $this->assertNull($this->host->viewerId(), 'nobody logged in');

        $userId = (int) $this->container['userModel']->create(['username' => 'ana', 'name' => 'Ana']);
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));

        $this->assertSame((string) $userId, $this->host->viewerId());
        $this->assertSame((string) $userId, (new Kanboard\Plugin\AgentChat\Model\ParticipantResolver($this->container))->forUser($userId)->id, 'the same id format as participants');
    }

    public function testEscape(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&amp;&#039;', $this->host->escape('<a href="x">&\''));
    }

    public function testUrlBuildsPluginRoutes(): void
    {
        // No routes registered in unit tests: Kanboard's query-string form names controller, action and plugin.
        $this->assertSame('?controller=RoomController&action=poll&project_id=3&plugin=AgentChat', $this->host->url('poll', ['project_id' => 3]));
        $this->assertSame('?controller=RoomController&action=send&project_id=3&plugin=AgentChat', $this->host->url('send', ['project_id' => 3]));
        $this->assertSame('?controller=RoomController&action=show&project_id=3&plugin=AgentChat', $this->host->url('chat', ['project_id' => 3]));

        $this->expectException(InvalidArgumentException::class);
        $this->host->url('nope');
    }

    public function testAssetUrlPointsAtTheVendoredKit(): void
    {
        $this->assertStringEndsWith('plugins/AgentChat/vendor-chatparts/assets/icons.svg', $this->host->assetUrl('icons.svg'));
    }

    public function testCsrfFieldIsKanboardsFormToken(): void
    {
        $field = $this->host->csrfField();
        $this->assertMatchesRegularExpression('~^<input type="hidden" name="csrf_token" value="[0-9a-f]+"/>$~', $field);
        preg_match('~value="([0-9a-f]+)"~', $field, $m);
        $this->assertTrue($this->container['token']->validateCSRFToken($m[1]));
    }

    public function testAvatarInitialsAndStableNamedTone(): void
    {
        $a = $this->host->avatarFor(new Participant('human', '7', 'Ada Lovelace'));
        $this->assertSame('AL', $a['initials']);
        $this->assertMatchesRegularExpression('/^[a-z]+$/', $a['tone']);
        $this->assertSame($a, $this->host->avatarFor(new Participant('human', '7', 'Ada Lovelace')));
        $this->assertSame('É', $this->host->avatarFor(new Participant('human', '8', 'élodie'))['initials']);
        $this->assertSame('?', $this->host->avatarFor(new Participant('human', '9', ''))['initials']);
    }
}
