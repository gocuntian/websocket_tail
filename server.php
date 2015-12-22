<?php
class G
{
    static $users = array();
    static $files = array();
    static $watchList = array();
    static $inotify;
}

$server = new swoole_websocket_server("0.0.0.0", 9502, SWOOLE_BASE);

$server->on('WorkerStart', function(swoole_websocket_server $server, $worker_id) {
    G::$inotify = inotify_init();
    swoole_event_add(G::$inotify, function ($ifd) use ($server) {
        $events = inotify_read(G::$inotify);
        if (!$events)
        {
            return;
        }

        foreach ($events as $event)
        {
            $filename = G::$watchList[$event['wd']];
            $line = fgets(G::$files[$filename]['fp']);
            if (!$line)
            {
                echo "fgets failed\n";
            }
            //遍历监听此文件的所有用户，进行广播
            foreach (G::$files[$filename]['users'] as $fd)
            {
                $server->push($fd, $line);
            }
        }
    });
});

$server->on('Message', function (swoole_websocket_server $server, $frame)
{
    echo "message: " . $frame->data;
    $result = json_decode($frame->data, true);
    $filename = $result['filename']; //以文件名作为数组的值，Key是fd

    $filename = __DIR__.'/'.$filename;
    if (!is_file($filename))
    {
        $server->push($frame->fd, "file[$filename] is not exist.\n");
        return;
    }

    //还没有创建inotify句柄
    if (empty(G::$files[$filename]['inotify_fd']))
    {
        //添加监听
        $wd = inotify_add_watch(G::$inotify, $filename, IN_MODIFY);
        $fp = fopen($filename, 'r');
        clearstatcache();
        $filesizelatest = filesize($filename);
        fseek($fp, $filesizelatest);

        G::$watchList[$wd] = $filename;
        G::$files[$filename]['inotify_fd'] = $wd;
        G::$files[$filename]['fp'] = $fp;
    }

    //清理掉其他文件的监听
    if (!empty(G::$users[$frame->fd]['watch_file']))
    {
        $oldfile = G::$users[$frame->fd]['watch_file'];
        $k = array_search($frame->fd, G::$files[$oldfile]['users']);
        unset(G::$files[$oldfile]['users'][$k]);
    }

    //用户监听的文件
    G::$users[$frame->fd]['watch_file'] = $filename;
    //文件被哪些人监听了
    G::$files[$filename]['users'][] = $frame->fd;
});

$server->on('close', function ($serv, $fd, $threadId) {
    if (G::$users[$fd]['watch_file'])
    {
        $file = G::$users[$fd]['watch_file'];
        $k = array_search($fd, G::$files[$file]['users']);
        unset(G::$files[$file]['users'][$k]);
        unset(G::$users[$fd]);
    }
});

$server->start();


