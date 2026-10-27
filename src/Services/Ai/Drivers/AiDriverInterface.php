<?php
declare(strict_types=1);

namespace App\Services\Ai\Drivers;

/**
 * AI 平台驱动接口：各平台方法类统一实现 chat。
 */
interface AiDriverInterface
{
    /**
     * @param array<string,mixed> $config 已解密的业务配置
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options temperature / max_tokens / model 等覆盖项
     * @return array{content:string,raw:array,usage?:array,model?:string}
     */
    public function chat(array $config, array $messages, array $options = []): array;

    /** 驱动标识，与 ai_platforms.driver 对应 */
    public function code(): string;
}
