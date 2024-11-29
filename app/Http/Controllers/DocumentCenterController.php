<?php

namespace App\Http\Controllers;

use App\Models\WebhookAthena;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocumentCenterController extends Controller
{
    public function handle(Request $request)
    {
        // Extrair o token do cabeçalho Authorization
        $authorizationHeader = $request->header('Authorization');

        // Validar o cabeçalho Authorization
        if (!$this->validateAuthorizationHeader($authorizationHeader)) {
            // return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Obter os dados do webhook
        $payload = $request->all();

        // Salvar os dados no banco de dados
        try {
            $this->saveWebhookData($payload);
            Log::channel('webhook')->info('Dados do webhook salvos com sucesso no banco de dados.');
        } catch (Exception $e) {
            Log::channel('webhook')->error('Erro ao salvar os dados do webhook no banco de dados.', ['message' => $e->getMessage()]);
            // return response()->json(['error' => 'Internal Server Error'], 500);
        }

        // Obter a URL do arquivo
        $fileUrl = $payload['URL_ARQUIVO'] ?? null;

        if ($fileUrl) {
            try {
                // Obter o caminho de destino
                $destinationFolder = $this->getDestinationPath($payload);

                // Não criar pastas se não existirem
                if (!file_exists($destinationFolder) || !is_dir($destinationFolder)) {
                    throw new Exception('Caminho de destino não existe: ' . $destinationFolder);
                }

                // Nome do arquivo a ser salvo
                $fileName = basename(parse_url($fileUrl, PHP_URL_PATH));

                // Caminho completo do arquivo de destino
                $destinationPath = $destinationFolder . DIRECTORY_SEPARATOR . $fileName;

                // Baixar o arquivo e salvar no destino
                $this->downloadAndSaveFile($fileUrl, $destinationPath);

                Log::channel('webhook')->info('Arquivo salvo com sucesso.', ['path' => $destinationPath]);
            } catch (Exception $e) {
                Log::channel('webhook')->error('Erro ao processar o arquivo.', ['message' => $e->getMessage()]);
                // return response()->json(['error' => 'Internal Server Error'], 500);
            }
        } else {
            Log::channel('webhook')->warning('URL do arquivo não encontrada no payload.');
        }

        // Registrar no log os dados do webhook
        Log::channel('webhook')->info('Webhook recebido.', $payload);

        // Retornar uma resposta ao remetente do webhook
        // return response()->json(['status' => 'success'], 200);
    }

    /**
     * Valida o cabeçalho Authorization.
     *
     * @param string|null $authorizationHeader
     * @return bool
     */
    private function validateAuthorizationHeader($authorizationHeader)
    {
        if (!$authorizationHeader) {
            return false;
        }

        // Verificar se o token está no formato Bearer
        if (preg_match('/Bearer\s(\S+)/', $authorizationHeader, $matches)) {
            $token = $matches[1];
        } else {
            return false;
        }

        // Verificar se o token é válido
        if ($token !== env('WEBHOOK_TOKEN')) {
            return false;
        }

        return true;
    }

    /**
     * Retorna o caminho de destino onde o arquivo será salvo.
     *
     * @param array $payload
     * @return string
     * @throws Exception
     */
    private function getDestinationPath($payload)
    {
        // Caminho base
        $basePath = 'Z:\\PASTAS DE CLIENTES';

        // Obter CNPJ do payload
        $cnpj = $payload['CNPJ'] ?? null;

        if (!$cnpj) {
            throw new Exception('CNPJ não fornecido no payload.');
        }

        // Sanitizar CNPJ
        $cnpj = $this->sanitizeFolderName($cnpj);

        // Encontrar a pasta do cliente que contém o CNPJ no nome
        $clientFolder = $this->findClientFolder($basePath, $cnpj);

        if (!$clientFolder) {
            throw new Exception('Pasta do cliente não encontrada para o CNPJ fornecido: ' . $cnpj);
            Log::channel('webhook')->error('Pasta do cliente não encontrada para o CNPJ fornecido: ' . $cnpj);
        }

        // Obter MESANO
        $mesAno = $payload['MESANO'] ?? null;
        if (!$mesAno) {
            throw new Exception('MESANO não fornecido no payload.');
        } else {
            // Assumindo MESANO no formato 'MMAAAA' com dois dígitos para o mês
            $mesAno = preg_replace('/\D/', '', $mesAno); // Remover caracteres não numéricos
            if (strlen($mesAno) == 6) {
                // Assumir MMYYYY
                $month = (int)substr($mesAno, 0, 2);
                $year = substr($mesAno, 2, 4);
            } else {
                throw new Exception('Formato de MESANO inválido.');
            }
        }

        // Obter departamento a partir de GRUPO
        $grupo = $payload['GRUPO'] ?? null;
        if (!$grupo) {
            throw new Exception('GRUPO não fornecido no payload.');
        }
        $department = $this->sanitizeFolderName($grupo);

        // Obter nome do mês com dois dígitos
        $monthNames = [
            '01' => 'Janeiro',
            '02' => 'Fevereiro',
            '03' => 'Março',
            '04' => 'Abril',
            '05' => 'Maio',
            '06' => 'Junho',
            '07' => 'Julho',
            '08' => 'Agosto',
            '09' => 'Setembro',
            '10' => 'Outubro',
            '11' => 'Novembro',
            '12' => 'Dezembro',
        ];

        $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);

        if (!isset($monthNames[$monthPadded])) {
            throw new Exception('Mês inválido.');
        }

        $monthName = $monthNames[$monthPadded];

        // Construir o caminho
        $destinationPath = $clientFolder . DIRECTORY_SEPARATOR
            . $year . DIRECTORY_SEPARATOR
            . $department . DIRECTORY_SEPARATOR
            . $monthName;

        // Verificar se o caminho de destino existe
        if (!file_exists($destinationPath) || !is_dir($destinationPath)) {
            throw new Exception('Caminho de destino não existe: ' . $destinationPath);
        }

        return $destinationPath;
    }

    /**
     * Remove caracteres inválidos para nomes de pastas.
     *
     * @param string $name
     * @return string
     */
    private function sanitizeFolderName($name)
    {
        // Remove caracteres inválidos para nomes de pastas
        // Substitui qualquer caractere inválido por underscore
        return preg_replace('/[<>:"\/\\\|\?\*]/', '_', $name);
    }

    /**
     * Encontra a pasta do cliente que contém o CNPJ no nome.
     *
     * @param string $basePath
     * @param string $cnpj
     * @return string|null
     */
    private function findClientFolder($basePath, $cnpj)
    {
        // Obter a lista de diretórios no caminho base
        $directories = glob($basePath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            if (strpos($dir, $cnpj) !== false) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Baixa o arquivo da URL e salva no caminho de destino.
     *
     * @param string $fileUrl
     * @param string $destinationPath
     * @throws Exception
     */
    private function downloadAndSaveFile($fileUrl, $destinationPath)
    {
        // Baixar o arquivo e salvar no destino
        $client = new \GuzzleHttp\Client(['verify' => false]); // Desabilita a verificação SSL para teste
        try {
            $response = $client->get($fileUrl, ['stream' => true]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception('Falha ao baixar o arquivo, código de status: ' . $response->getStatusCode());
            }

            $body = $response->getBody();

            $resource = fopen($destinationPath, 'w');

            if ($resource === false) {
                throw new Exception('Falha ao abrir o arquivo para escrita: ' . $destinationPath);
            }

            while (!$body->eof()) {
                fwrite($resource, $body->read(1024));
            }

            fclose($resource);
        } catch (\Exception $e) {
            Log::channel('webhook')->error('Erro ao baixar e salvar o arquivo:', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new Exception('Erro ao baixar e salvar o arquivo: ' . $e->getMessage());
        }
    }

    private function saveWebhookData($payload)
    {
        try {
            WebhookAthena::create($payload);
        } catch (Exception $e) {
            throw new Exception('Erro ao salvar dados no banco de dados: ' . $e->getMessage());
        }
    }
}
