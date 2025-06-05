<?php

namespace Psf\Http;

class ApiDocsGenerator{
    private array $routes;

    public function __construct(array $routes){
        $this->routes = $routes;
    }

    /**
     * Prepara os dados e renderiza o template da documentação da API.
     */
    public function generate(): void{
        $requests = $this->prepareRequestsForDocs();
        
        // Inclui o template, que terá acesso à variável $requests.
        require __DIR__ . '/templates/docsTemplate.phtml';
    }

    /**
     * Filtra e formata as rotas para serem usadas no template da documentação.
     */
    private function prepareRequestsForDocs(): array{
        $getRoutesDocs = array_filter($this->routes, function($item) {
            return isset($item['attributes']['arguments']['docs']);
        });

        $itens = [];
        if ($getRoutesDocs) {
            foreach ($getRoutesDocs as $item) {
                $arrAdd = array_merge([
                    'method'      => $item['attributes']['arguments']['method'],
                    'uri'         => $item['attributes']['arguments']['path'],
                    'version'     => $item['attributes']['arguments']['version'],
                    'protected'   => in_array('authentication', $item['attributes']['arguments']['middlewares'] ?? []),
                ], $item['attributes']['arguments']['docs']);

                if (empty($arrAdd['title'])) {
                    $arrAdd['title'] = $item['name'];
                }

                $itens[] = $arrAdd;
            }
        }
        return $itens;
    }
} 