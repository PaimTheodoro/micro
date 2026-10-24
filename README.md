# PSF Micro-Framework PHP (Beta)

Bem-vindo ao **PSF Micro-Framework**, um micro-framework PHP otimizado para o desenvolvimento de APIs, com suporte a bancos de dados MySQL, SQLServer, PostgreSQL e SQLite. Este framework é projetado para ser leve, flexível e fácil de usar, com um sistema de roteamento robusto e um *query builder* eficiente. Atualmente, o framework está em fase Beta.

## Requisitos

- PHP 8.1 ou superior
- Extensão `PDO` habilitada
- Opcional: Extensão `APCu` para otimização de desempenho

## Funcionalidades

- **Query Builder**: Suporte completo para MySQL, SQLServer e PostgreSQL, facilitando a construção de consultas, inclusões e alterações SQL de forma programática.
- **Sistema de Roteamento**: Focado em APIs, com suporte para rotas GET, POST, PUT, DELETE e rotas dinâmicas.
- **Otimização com APCu**: Implementação opcional para otimização do desempenho das classes através de caching com APCu.
- **Fácil Integração**: Flexível para ser integrado com outras bibliotecas e pacotes, além de suportar a expansão de funcionalidades.

## Instalação

Você pode instalar o framework diretamente através do Composer:

```bash
composer require psf/framework
```

## Configuração

1. Verifique se o PHP está configurado corretamente no seu servidor.
2. Habilite a extensão APCu, se desejar utilizar a otimização de cache.
3. Configure a conexão com o banco de dados no arquivo de configuração.

Exemplo de configuração do projeto: (esta configuração deve ser adicionada ao arquivo de configuração)

```php
use Psf\Enumerators\DBDriver;

return [
    'environment' => 'dev', // 'prd' mantém o cache de rotas estático (limpe no deploy)

    'db' => [
        'default' => [
            'driver'   => DBDriver::MySQL, // MySQL, PostgreSQL, SQLServer ou SQLite
            'hostname' => 'localhost',     // 'host', 'user' e 'pass' também são aceitos
            'username' => 'root',
            'password' => '',
            'database' => 'meu_banco',     // SQLite: caminho do arquivo ou ':memory:'
            'port'     => 3306,
        ],
    ],

    'jwt' => [
        'secret' => 'uma-chave-longa-e-aleatoria', // mínimo de 16 caracteres
    ],

    'settings' => [
        'controllers'    => [__DIR__ . '/app/Controllers'],
        'debug'          => false, // true expõe mensagem/arquivo/linha dos erros de banco na resposta
        'authentication' => [App\Auth::class, 'verify'],
        'cors'           => [
            'origins'     => ['https://app.exemplo.com'], // ou '*'
            'credentials' => false,
        ],
    ],
];
```

## Exemplo de Uso

### Roteamento

Defina suas rotas facilmente utilizando o sistema de roteamento:

```php
#[Router(version: 1, path: 'usuario', method: 'GET', middlewares: ['authentication'])]
public function recuperarDadosUsuario() : array{
	...
}
```

### Query Builder

Construa consultas SQL com simplicidade:

```php
$query = Usuario::find()
->andWhere([Usuario::class . '.email' => $email])
->one();
```

## Contribuindo

Este projeto está em fase Beta, e aceitamos sugestões e feedbacks. No momento, a contribuição está limitada a um grupo privado devido à licença do framework.

## Licença

Este projeto é licenciado sob uma licença privada. Entre em contato para mais informações sobre o uso e distribuição.

## Contato

Para dúvidas ou suporte, entre em contato pelo e-mail: theodoro@porglin.com

---
