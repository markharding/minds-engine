<?php
namespace Minds\Core\Feeds\Activity\RichEmbed;

use Minds\Core\Config\Config;
use Minds\Exceptions\ServerErrorException;

class Manager
{
    public function __construct(private Iframely $iframely, private Config $config)
    {
    }

    /**
     * @param string $url
     * @return array - json payload
     */
    public function getRichEmbed(string $url): array
    {
        $iframelyConfig = $this->config->get('iframely');

        $queryParamString = http_build_query([
            'origin' => $iframelyConfig['origin'],
            'api_key' => $iframelyConfig['key'],
            'url' => $url,
        ]);

        $response = $this->iframely->request('GET', '?' . $queryParamString);
        $meta = json_decode($response->getBody()->getContents(), true);

        if (isset($meta['status']) && $meta['status'] !== 200) {
            throw new ServerErrorException('Unable to fetch data for given URL');
        }

        $meta['meta']['description'] = html_entity_decode($meta['meta']['description'], ENT_QUOTES); //Decode HTML entities.

        return $meta;
    }
}
