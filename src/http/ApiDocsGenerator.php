<?php

namespace Psf\Http;

class ApiDocsGenerator{
    private array $routes;

    public function __construct(array $routes){
        $this->routes = $routes;
    }

    /**
     * Prepara os dados e renderiza o template da documentação da API.
     * Com `?format=json` retorna o documento bruto (tags + requests) em JSON,
     * útil pra importar em Postman/Insomnia ou gerar outro client.
     */
    public function generate(): void{
        $document = $this->buildDocsDocument();

        if(($_GET['format'] ?? '') === 'json'){
            header('Content-Type: application/json');
            echo json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        $requests = $document['requests'];
        $tags     = $document['tags'];
        $baseUrl  = rtrim(\PSF::getConfig()->project['domain'] ?? '', '/');

        // Inclui o template, que terá acesso às variáveis $requests, $tags e $baseUrl.
        require __DIR__ . '/templates/docsTemplate.phtml';
    }

    /**
     * Filtra e formata as rotas documentadas, agrupando por tag
     * (`docs.category`, com `docs.group` como alias legado).
     */
    private function buildDocsDocument(): array{
        $getRoutesDocs = array_filter($this->routes, function($item) {
            return isset($item['attributes']['arguments']['docs']);
        });

        $tags = [];
        $requests = [];

        foreach ($getRoutesDocs as $item) {
            $docs = $item['attributes']['arguments']['docs'];
            $tag  = $docs['category'] ?? $docs['group'] ?? 'Geral';

            if (!in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }

            $arrAdd = array_merge([
                'method'      => $item['attributes']['arguments']['method'],
                'uri'         => $item['attributes']['arguments']['path'],
                'version'     => $item['attributes']['arguments']['version'],
                'protected'   => in_array('authentication', $item['attributes']['arguments']['middlewares'] ?? []),
            ], $docs, [
                'tag' => $tag,
                'id'  => $this->buildRequestId($item['attributes']['arguments']['method'], $item['attributes']['arguments']['path']),
            ]);

            if (empty($arrAdd['title'])) {
                $arrAdd['title'] = $item['name'];
            }

            $requests[] = $arrAdd;
        }

        return ['tags' => $tags, 'requests' => $requests];
    }

    /** Gera um id estável (pra âncora/data-attribute) a partir do método + path da rota. */
    private function buildRequestId(string $method, string $path): string{
        return strtolower($method) . '-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $path), '-');
    }
}
