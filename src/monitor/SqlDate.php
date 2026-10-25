<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectInterface;
use Psf\Database\Dialect\SQLServerDialect;

/**
 * Formato de datetime para valores **ligados** a colunas datetime.
 *
 * O SQL Server lê `'Y-m-d H:i:s'` conforme a linguagem da sessão (em
 * `Português`, dia e mês trocam); a forma ISO 8601 `Y-m-d\TH:i:s` é lida igual
 * em qualquer linguagem. Só o SQL Server recebe o `T`: no SQLite a comparação é
 * textual e `'...T10:00:00' > '... 10:00:00'` quebraria linhas gravadas com espaço.
 * Tudo o que é guardado/comparado em PHP continua `Y-m-d H:i:s`.
 */
final class SqlDate
{
    /**
     * `Y-m-d H:i:s` → forma para o dialeto; entrada em outro formato volta igual.
     */
    public static function bind(string $ymdHis, DialectInterface $dialect): string
    {
        if (!$dialect instanceof SQLServerDialect
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $ymdHis) !== 1) {
            return $ymdHis;
        }
        return substr($ymdHis, 0, 10) . 'T' . substr($ymdHis, 11);
    }
}
