<?php

namespace Tests\Unit;

use App\Enums\TipoRestricaoCronograma;
use PHPUnit\Framework\TestCase;

class TipoRestricaoCronogramaTest extends TestCase
{
    public function test_mapeia_os_8_codigos_do_mspdi(): void
    {
        $this->assertSame(TipoRestricaoCronograma::AssimQuePossivel, TipoRestricaoCronograma::fromCodigoMsProject(0));
        $this->assertSame(TipoRestricaoCronograma::OMaisTardePossivel, TipoRestricaoCronograma::fromCodigoMsProject(1));
        $this->assertSame(TipoRestricaoCronograma::DeveComecarEm, TipoRestricaoCronograma::fromCodigoMsProject(2));
        $this->assertSame(TipoRestricaoCronograma::DeveTerminarEm, TipoRestricaoCronograma::fromCodigoMsProject(3));
        $this->assertSame(TipoRestricaoCronograma::NaoComecarAntesDe, TipoRestricaoCronograma::fromCodigoMsProject(4));
        $this->assertSame(TipoRestricaoCronograma::NaoComecarDepoisDe, TipoRestricaoCronograma::fromCodigoMsProject(5));
        $this->assertSame(TipoRestricaoCronograma::NaoTerminarAntesDe, TipoRestricaoCronograma::fromCodigoMsProject(6));
        $this->assertSame(TipoRestricaoCronograma::NaoTerminarDepoisDe, TipoRestricaoCronograma::fromCodigoMsProject(7));
    }

    public function test_codigo_desconhecido_retorna_null(): void
    {
        $this->assertNull(TipoRestricaoCronograma::fromCodigoMsProject(99));
    }

    public function test_asap_e_alap_nao_impoe_data_fixa(): void
    {
        $this->assertFalse(TipoRestricaoCronograma::AssimQuePossivel->imposDataFixa());
        $this->assertFalse(TipoRestricaoCronograma::OMaisTardePossivel->imposDataFixa());
    }

    public function test_demais_tipos_impoe_data_fixa(): void
    {
        $this->assertTrue(TipoRestricaoCronograma::DeveComecarEm->imposDataFixa());
        $this->assertTrue(TipoRestricaoCronograma::DeveTerminarEm->imposDataFixa());
        $this->assertTrue(TipoRestricaoCronograma::NaoComecarAntesDe->imposDataFixa());
        $this->assertTrue(TipoRestricaoCronograma::NaoComecarDepoisDe->imposDataFixa());
        $this->assertTrue(TipoRestricaoCronograma::NaoTerminarAntesDe->imposDataFixa());
        $this->assertTrue(TipoRestricaoCronograma::NaoTerminarDepoisDe->imposDataFixa());
    }
}
