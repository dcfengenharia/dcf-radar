<?php

namespace Tests\Unit;

use App\Enums\TipoRelacionamentoPredecessora;
use PHPUnit\Framework\TestCase;

class TipoRelacionamentoPredecessoraTest extends TestCase
{
    public function test_mapeia_os_4_codigos_do_mspdi(): void
    {
        $this->assertSame(TipoRelacionamentoPredecessora::FinishToFinish, TipoRelacionamentoPredecessora::fromCodigoMsProject(0));
        $this->assertSame(TipoRelacionamentoPredecessora::FinishToStart, TipoRelacionamentoPredecessora::fromCodigoMsProject(1));
        $this->assertSame(TipoRelacionamentoPredecessora::StartToFinish, TipoRelacionamentoPredecessora::fromCodigoMsProject(2));
        $this->assertSame(TipoRelacionamentoPredecessora::StartToStart, TipoRelacionamentoPredecessora::fromCodigoMsProject(3));
    }

    public function test_codigo_desconhecido_retorna_null_nunca_inventa_tipo(): void
    {
        $this->assertNull(TipoRelacionamentoPredecessora::fromCodigoMsProject(99));
    }
}
