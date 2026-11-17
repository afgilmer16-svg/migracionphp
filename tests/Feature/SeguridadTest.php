<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** Comprobaciones de seguridad y de limpieza del proyecto. */
class SeguridadTest extends TestCase
{
    public function test_la_ruta_api_user_del_esqueleto_original_ya_no_existe(): void
    {
        $this->getJson('/api/user')->assertNotFound();
    }

    public function test_rutas_inexistentes_de_la_api_responden_json(): void
    {
        $this->getJson('/api/no-existe')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_el_endpoint_de_salud_responde(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_public_htaccess_existe_y_envia_todo_al_front_controller(): void
    {
        $file = base_path('public/.htaccess');

        $this->assertFileExists($file);
        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $content);
        $this->assertStringContainsString('Require all denied', $content);
    }

    public function test_htaccess_de_la_raiz_redirige_a_public_y_bloquea_el_env(): void
    {
        $content = (string) file_get_contents(base_path('.htaccess'));

        $this->assertStringContainsString('public/$1', $content);
        $this->assertDoesNotMatchRegularExpression('/RewriteRule\s+\S+\s+index\.php/', $content, 'La raíz ya no tiene index.php.');
        // Regla que prohíbe cualquier archivo oculto (.env, .git...) con 403.
        $this->assertStringContainsString('(^|/)\.(?!well-known/) - [F,L]', $content);
        $this->assertMatchesRegularExpression('/\(app\|bootstrap\|config\|database\|.*storage.*vendor\)/', $content);
    }

    public function test_el_punto_de_entrada_unico_esta_en_public(): void
    {
        $this->assertFileExists(base_path('public/index.php'));
        $this->assertFileDoesNotExist(base_path('index.php'));
        $this->assertFileDoesNotExist(base_path('server.php'));
    }

    public function test_env_esta_ignorado_por_git_y_el_ejemplo_no_activa_debug(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertMatchesRegularExpression('/^\.env\R/m', $gitignore);

        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
    }

    public function test_no_quedan_restos_del_esqueleto_antiguo(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Region.php'), 'Duplicado de app/Models/Region.php');
        $this->assertFileDoesNotExist(base_path('webpack.mix.js'), 'Laravel Mix no se usa en una API');
        $this->assertFileDoesNotExist(base_path('app/Http/Kernel.php'));
        $this->assertFileDoesNotExist(base_path('app/Console/Kernel.php'));
    }

    public function test_las_migraciones_usan_clases_anonimas(): void
    {
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $content = (string) file_get_contents($file);

            $this->assertStringContainsString('return new class extends Migration', $content, basename($file));
            $this->assertStringNotContainsString('class_exists', $content, basename($file));
        }
    }
}
