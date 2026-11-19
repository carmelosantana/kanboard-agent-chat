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
            ."![same](http://kb.example/d.png) ![relative](img/e.png) ![otherport](http://kb.example:9000/f.png)"
        );

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString(':9000', $html, 'same host on another port is another origin');
        $this->assertStringContainsString('<img src="/files/b.png"', $html);
        $this->assertStringContainsString('<img src="http://kb.example/d.png"', $html);
        $this->assertStringContainsString('<img src="img/e.png"', $html);
        $this->assertSame(3, substr_count($html, '<img'));
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

        $this->assertMatchesRegularExpression('~<a href="[^"]*(task_id=12|task/12)[^"]*">#12</a>~', $html);
    }

    public function testTranslatedArgumentIsEscapedOnceInAComponent(): void
    {
        $this->assertSame('answered for <b>&', $this->host->t('answered for %s', '<b>&'), 't() leaves escaping to the kit');

        $html = (string) new Receipt($this->host, ['bot' => 'project-bot', 'asked_by' => ['name' => '<b>&']]);

        $this->assertStringContainsString('answered for &lt;b&gt;&amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function testEscape(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&amp;&#039;', $this->host->escape('<a href="x">&\''));
    }

    public function testUrlBuildsPluginRoutes(): void
    {
        // Without registered routes Kanboard falls back to ?controller=…&action=…; with them, the pretty path.
        $this->assertMatchesRegularExpression('~controller=RoomController&action=poll&project_id=3&plugin=AgentChat|agentchat/3/poll~', $this->host->url('poll', ['project_id' => 3]));
        $this->assertMatchesRegularExpression('~action=send|agentchat/3/send~', $this->host->url('send', ['project_id' => 3]));
        $this->assertMatchesRegularExpression('~action=show|project/3/chat~', $this->host->url('chat', ['project_id' => 3]));

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
