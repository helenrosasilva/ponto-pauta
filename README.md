# Ponto & Pauta

Loja demonstrativa de papelaria criativa, com catálogo ilustrado, cadastro de produtos e gerenciamento local da equipe.

## Requisitos

- PHP 8.0 ou superior
- Extensões `PDO_SQLite` e `SQLite3`

No Windows, instale o PHP pelo `winget`:

```powershell
winget install --id PHP.PHP.8.4 --exact
```

## Executar localmente

Na pasta do projeto, inicie o servidor embutido do PHP:

```powershell
php -S 127.0.0.1:8000
```

Abra `http://127.0.0.1:8000/Index.html` no navegador. Se o comando `php` não for reconhecido após a instalação, feche e abra o terminal novamente.

## Dados

Produtos e equipe ficam em SQLite. O banco novo é criado fora da pasta pública em `../Ponto-Pauta-data/ponto-pauta.sqlite`. Para usar outro local, configure `PONTO_PAUTA_DB_PATH` antes de iniciar o servidor e mantenha o arquivo fora da pasta pública.

O catálogo inicial contém dez itens de papelaria. Produtos e funcionários podem ser gerenciados na **Área da equipe**. O formulário de funcionário armazena nome, CPF e e-mail; o CPF precisa ter onze números e ser único.

Esta aplicação foi preparada para uso local. Antes de disponibilizá-la na internet, configure autenticação e proteção adequada para os dados pessoais da equipe.
