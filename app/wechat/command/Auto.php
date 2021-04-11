<?php

namespace app\wechat\command;

use app\wechat\service\MediaService;
use app\wechat\service\WechatService;
use think\admin\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

/**
 * 向指定用户推送消息
 * Class Auto
 * @package app\wechat\command
 */
class Auto extends Command
{
    /** @var string */
    private $openid;

    /**
     * 配置消息指令
     */
    protected function configure()
    {
        $this->setName('xadmin:fanauto');
        $this->addArgument('openid', Argument::OPTIONAL, 'wechat user openid', '');
        $this->addArgument('autocode', Argument::OPTIONAL, 'wechat auto message', '');
        $this->setDescription('Wechat Users Push AutoMessage for ThinkAdmin');
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return void
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function execute(Input $input, Output $output)
    {
        $code = $input->getArgument('autocode');
        $this->openid = $input->getArgument('openid');
        if (empty($code)) $this->setQueueError("Message Code cannot be empty");
        if (empty($this->openid)) $this->setQueueError("Wechat Openid cannot be empty");

        // 查询微信消息对象
        $map = ['code' => $code, 'status' => 1];
        $data = $this->app->db->name('WechatAuto')->where($map)->find();
        if (empty($data)) $this->setQueueError("Message Data Query failed");

        // 发送微信客服消息
        $this->_buildMessage($data);
    }

    /**
     * 关键字处理
     * @param array $data
     * @return void
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function _buildMessage(array $data)
    {
        $type = strtolower($data['type']);
        $result = [0, '待发货的消息不符合规则'];
        if ($type === 'text' && !empty($data['content'])) {
            $result = $this->_sendMessage('text', ['content' => $data['content']]);
        }
        if ($type === 'voice' && !empty($data['voice_url'])) {
            if ($mediaId = MediaService::instance()->upload($data['voice_url'], 'voice')) {
                $result = $this->_sendMessage('voice', ['media_id' => $mediaId]);
            }
        }
        if ($type === 'image' && !empty($data['image_url'])) {
            if ($mediaId = MediaService::instance()->upload($data['image_url'], 'image')) {
                $result = $this->_sendMessage('image', ['media_id' => $mediaId]);
            }
        }
        if ($type === 'news') {
            [$item, $news] = [MediaService::instance()->news($data['news_id']), []];
            if (!empty($item['articles'])) {
                $host = sysconf('base.site_host');
                foreach ($item['articles'] as $vo) array_push($news, [
                    'url'   => url("@wechat/api.view/item/id/{$vo['id']}", [], false, $host)->build(),
                    'title' => $vo['title'], 'picurl' => $vo['local_url'], 'description' => $vo['digest'],
                ]);
                $result = $this->_sendMessage('news', ['articles' => $news]);
            }
        }
        if ($type === 'music' && !empty($data['music_url']) && !empty($data['music_title']) && !empty($data['music_desc'])) {
            $mediaId = $data['music_image'] ? MediaService::instance()->upload($data['music_image'], 'image') : '';
            $result = $this->_sendMessage('music', [
                'hqmusicurl'  => $data['music_url'], 'musicurl' => $data['music_url'],
                'description' => $data['music_desc'], 'title' => $data['music_title'], 'thumb_media_id' => $mediaId,
            ]);
        }
        if ($type === 'video' && !empty($data['video_url']) && !empty($data['video_desc']) && !empty($data['video_title'])) {
            $video = ['title' => $data['video_title'], 'introduction' => $data['video_desc']];
            if ($mediaId = MediaService::instance()->upload($data['video_url'], 'video', $video)) {
                $result = $this->_sendMessage('video', ['media_id' => $mediaId, 'title' => $data['video_title'], 'description' => $data['video_desc']]);
            }
        }
        if (empty($result[0])) {
            $this->setQueueError($result[1]);
        } else {
            $this->setQueueSuccess($result[1]);
        }
    }

    /**
     * 推送客服消息
     * @param string $type 消息类型
     * @param array $data 消息对象
     * @return array
     */
    private function _sendMessage(string $type, array $data): array
    {
        try {
            WechatService::WeChatCustom()->send([
                $type => $data, 'touser' => $this->openid, 'msgtype' => $type,
            ]);
            return [1, '微信消息推送成功'];
        } catch (\Exception $exception) {
            return [0, $exception->getMessage()];
        }
    }
}