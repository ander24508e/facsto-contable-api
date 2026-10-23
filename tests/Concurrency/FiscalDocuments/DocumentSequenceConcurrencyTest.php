<?php

namespace Tests\Concurrency\FiscalDocuments;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Services\DocumentSequenceAllocator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DocumentSequenceConcurrencyTest extends TestCase
{
    public function test_two_postgres_connections_reserve_distinct_sequential_numbers(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('La prueba de concurrencia necesita pcntl y sockets Unix.');
        }

        $previousConnection = DB::getDefaultConnection();
        $schema = 'facsto_seq_test_'.bin2hex(random_bytes(5));
        $db = DB::connection('pgsql');
        $this->assertSame('pgsql', $db->getDriverName());
        $db->statement('CREATE SCHEMA '.$schema);
        $db->statement('SET search_path TO '.$schema);
        $db->statement('CREATE TABLE document_sequences (id bigserial primary key, company_id bigint not null, establishment_id bigint not null, emission_point_id bigint not null, document_type varchar(24) not null, current_number bigint not null default 0, is_active boolean not null default true, created_at timestamp null, updated_at timestamp null, unique(company_id, establishment_id, emission_point_id, document_type))');
        $db->table('document_sequences')->insert(['company_id' => 1, 'establishment_id' => 1, 'emission_point_id' => 1, 'document_type' => 'INVOICE', 'current_number' => 0, 'is_active' => true]);
        DB::setDefaultConnection('pgsql');

        $children = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('No se pudo iniciar un proceso de concurrencia.');
                }
                if ($pid === 0) {
                    fclose($parentSocket);
                    fread($childSocket, 1);
                    DB::purge('pgsql');
                    $childDb = DB::connection('pgsql');
                    $childDb->statement('SET search_path TO '.$schema);
                    $number = $childDb->transaction(fn (): int => app(DocumentSequenceAllocator::class)->next(1, 1, 1, DocumentType::INVOICE));
                    fwrite($childSocket, (string) $number."\n");
                    fclose($childSocket);
                    exit(0);
                }
                fclose($childSocket);
                $children[] = ['pid' => $pid, 'socket' => $parentSocket];
            }

            foreach ($children as $child) {
                fwrite($child['socket'], 'G');
            }
            $numbers = array_map(fn (array $child): int => (int) trim((string) fgets($child['socket'])), $children);
            sort($numbers);
            foreach ($children as $child) {
                pcntl_waitpid($child['pid'], $status);
                fclose($child['socket']);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame([1, 2], $numbers);
        } finally {
            DB::purge('pgsql');
            $cleanup = DB::connection('pgsql');
            $cleanup->statement('SET search_path TO public');
            $cleanup->statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            DB::setDefaultConnection($previousConnection);
        }
    }
}
