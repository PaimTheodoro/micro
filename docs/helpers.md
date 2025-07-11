# Helpers

Funções utilitárias para tarefas comuns, localizadas em `src/helpers/`.

### Exemplos reais

- `Format::parseDate('2024-01-01', 'd/m/Y')` — Formata datas
- `UUID::generate(4)` — Gera UUID v4
- `Valid::email('a@b.com')` — Valida e-mail
- `Text::slugify('Olá Mundo!')` — Gera slug
- `Device::getInfo()` — Detecta informações do dispositivo
- `CheckFields::check([...])` — Valida campos de entrada
- `Apis::BrasilApiFindCep('01001000')` — Consulta CEP via BrasilAPI 