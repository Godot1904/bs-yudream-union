<?php

namespace SITMC\YudreamUnion\Services;

/**
 * 对端 TLS 叶子证书指纹(SHA-256,PEM 文本口径,站内自洽比对用)。
 *
 * TOFU 辅助:peer-add 时抓取并随受信站条目落库;GUI 展示、变更时审计告警。
 * 注:当前为"记录+告警"级防护,未做传输层强制钉扎(协议 §4-1 为可选项)。
 */
final class TlsFingerprint
{
    public static function of(string $httpsUrl): ?string
    {
        $url = rtrim($httpsUrl, '/') . '/api/plugins/yudream-union/peer/meta';
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CERTINFO => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        /** @var array<int, array<string, mixed>>|false $certinfo */
        $certinfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        curl_close($ch);
        $leaf = is_array($certinfo) && isset($certinfo[0]['Cert']) ? (string) $certinfo[0]['Cert'] : '';
        if ($leaf === '') {
            return null;
        }
        return hash('sha256', $leaf);
    }
}
