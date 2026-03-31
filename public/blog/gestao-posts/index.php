<?php
require_once '../../cadastro/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Posts - Blog PetFlow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="max-w-7xl mx-auto px-4 py-10">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-indigo-800">Gerenciar Posts</h1>
            <button onclick="abrirModal()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
                Novo Post
            </button>
        </div>

        <table class="min-w-full bg-white rounded-xl shadow-md overflow-hidden">
            <thead class="bg-indigo-100 text-left text-gray-700">
                <tr>
                    <th class="px-6 py-3">Imagem</th>
                    <th class="px-6 py-3">Título</th>
                    <th class="px-6 py-3">Slug</th>
                    <th class="px-6 py-3">Categoria</th>
                    <th class="px-6 py-3">Data</th>
                    <th class="px-6 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody id="posts-list">
                <!-- Conteúdo gerado via JS -->
            </tbody>
        </table>
    </div>

    <!-- Modal de Novo/Editar Post -->
    <div id="modal-post" class="fixed inset-0 bg-black bg-opacity-40 flex justify-center items-center hidden">
        <div class="bg-white p-6 rounded-xl w-full max-w-2xl relative">
            <h2 id="modal-titulo" class="text-xl font-bold mb-4 text-indigo-700">Novo Post</h2>

            <form id="form-post" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="post-id">
                <input type="hidden" name="remover_imagem" id="remover_imagem" value="0">

                <div class="mb-4">
                    <label for="titulo" class="block font-medium mb-1">Título</label>
                    <input type="text" name="titulo" id="titulo" required class="w-full border border-gray-300 rounded px-4 py-2">
                </div>

                <div class="mb-4">
                    <label for="slug" class="block font-medium mb-1">Slug</label>
                    <input type="text" name="slug" id="slug" required class="w-full border border-gray-300 rounded px-4 py-2">
                    <p class="text-xs text-gray-500 mt-1">Ex: instagram-para-petshops</p>
                </div>

                <div class="mb-4">
                    <label for="resumo" class="block font-medium mb-1">Resumo</label>
                    <textarea name="resumo" id="resumo" rows="2" class="w-full border border-gray-300 rounded px-4 py-2"></textarea>
                </div>

                <div class="mb-4">
                    <label for="conteudo" class="block font-medium mb-1">Conteúdo</label>
                    <textarea name="conteudo" id="conteudo" rows="6" class="w-full border border-gray-300 rounded px-4 py-2"></textarea>
                </div>

                <div class="mb-4">
                    <label for="id_categoria" class="block font-medium mb-1">Categoria</label>
                    <select name="id_categoria" id="id_categoria" required class="w-full border border-gray-300 rounded px-4 py-2">
                        <!-- categorias preenchidas via JS -->
                    </select>
                </div>

                <!-- IMAGEM (CDN URL) -->
                <div class="mb-4">
                    <label for="imagem_capa" class="block font-medium mb-1">Imagem de capa (URL CDN)</label>

                    <div class="flex items-start gap-4">
                        <div class="w-32 h-20 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
                            <img id="preview-img" src="" alt="" class="hidden w-full h-full object-cover">
                            <span id="preview-placeholder" class="text-xs text-gray-400 px-2 text-center">Sem imagem</span>
                        </div>

                        <div class="flex-1">
                            <input
                                type="url"
                                name="imagem_capa"
                                id="imagem_capa"
                                placeholder="https://cdn.seudominio.com/imagens/capa.jpg"
                                class="w-full border border-gray-300 rounded px-4 py-2"
                            >
                            <p class="text-xs text-gray-500 mt-1">Cole um link http(s). Ex.: Cloudinary, Bunny, Imgix, etc.</p>

                            <div class="mt-2 flex items-center gap-3">
                                <button type="button" onclick="testarImagemUrl()" class="text-sm text-indigo-700 hover:underline">
                                    Testar preview
                                </button>

                                <button type="button" onclick="marcarRemocaoImagem()" class="text-sm text-red-600 hover:underline">
                                    Remover imagem
                                </button>

                                <span id="remocao-msg" class="text-xs text-red-500 hidden">
                                    Imagem será removida ao salvar.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" onclick="fecharModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                        Cancelar
                    </button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal-post');
        const form  = document.getElementById('form-post');

        const previewImg         = document.getElementById('preview-img');
        const previewPlaceholder = document.getElementById('preview-placeholder');
        const inputImagem        = document.getElementById('imagem_capa');
        const removerImagemInput = document.getElementById('remover_imagem');
        const remocaoMsg         = document.getElementById('remocao-msg');

        function escapeHtml(str) {
            return String(str ?? '')
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
        }

        function setPreview(src) {
            const url = (src ?? '').trim();
            if (!url) {
                previewImg.src = '';
                previewImg.classList.add('hidden');
                previewPlaceholder.classList.remove('hidden');
                return;
            }
            previewImg.src = url;
            previewImg.classList.remove('hidden');
            previewPlaceholder.classList.add('hidden');
        }

        function abrirModal(post = null) {
            modal.classList.remove('hidden');
            document.getElementById('modal-titulo').innerText = post ? 'Editar Post' : 'Novo Post';

            form.reset();
            document.getElementById('post-id').value = '';
            removerImagemInput.value = '0';
            remocaoMsg.classList.add('hidden');
            setPreview('');

            if (post) {
                document.getElementById('post-id').value = post.id ?? '';
                document.getElementById('titulo').value = post.titulo ?? '';
                document.getElementById('slug').value = post.slug ?? '';
                document.getElementById('resumo').value = post.resumo ?? '';
                document.getElementById('conteudo').value = post.conteudo ?? '';
                document.getElementById('id_categoria').value = post.id_categoria ?? '';
                document.getElementById('imagem_capa').value = post.imagem_capa ?? '';

                if (post.imagem_capa_url) setPreview(post.imagem_capa_url);
            }
        }

        function fecharModal() {
            modal.classList.add('hidden');
        }

        function marcarRemocaoImagem() {
            removerImagemInput.value = '1';
            remocaoMsg.classList.remove('hidden');
            inputImagem.value = '';
            setPreview('');
        }

        function testarImagemUrl() {
            removerImagemInput.value = '0';
            remocaoMsg.classList.add('hidden');
            setPreview(inputImagem.value);
        }

        // Se digitar/colar URL, já tenta preview automaticamente
        inputImagem.addEventListener('blur', () => {
            if (inputImagem.value.trim()) {
                removerImagemInput.value = '0';
                remocaoMsg.classList.add('hidden');
                setPreview(inputImagem.value);
            }
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const dados = new FormData(form);

            axios.post('posts_controller.php', dados)
                .then(() => {
                    fecharModal();
                    carregarPosts();
                })
                .catch(err => {
                    alert(err?.response?.data?.error || 'Erro ao salvar.');
                });
        });

        function carregarPosts() {
            axios.get('posts_controller.php?action=list')
                .then(res => {
                    const posts = res.data;
                    const lista = document.getElementById('posts-list');
                    lista.innerHTML = '';

                    posts.forEach(p => {
                        const img = p.imagem_capa_url
                            ? `<img src="${escapeHtml(p.imagem_capa_url)}" class="w-14 h-10 object-cover rounded-md border" alt="">`
                            : `<div class="w-14 h-10 rounded-md border bg-gray-50 flex items-center justify-center text-[10px] text-gray-400">sem</div>`;

                        const safePostJson = encodeURIComponent(JSON.stringify(p));

                        lista.innerHTML += `
                            <tr class="border-t">
                                <td class="px-6 py-3">${img}</td>
                                <td class="px-6 py-3">${escapeHtml(p.titulo)}</td>
                                <td class="px-6 py-3">${escapeHtml(p.slug)}</td>
                                <td class="px-6 py-3">${escapeHtml(p.categoria)}</td>
                                <td class="px-6 py-3">${escapeHtml(p.data_publicacao)}</td>
                                <td class="px-6 py-3 text-right space-x-2">
                                    <button onclick='abrirModal(JSON.parse(decodeURIComponent("${safePostJson}")))' class="text-indigo-600 hover:underline">Editar</button>
                                    <button onclick='excluirPost(${p.id})' class="text-red-600 hover:underline">Excluir</button>
                                </td>
                            </tr>
                        `;
                    });
                })
                .catch(err => {
                    alert(err?.response?.data?.error || 'Erro ao carregar posts.');
                });
        }

        function excluirPost(id) {
            if (!confirm('Tem certeza que deseja excluir este post?')) return;

            const dados = new FormData();
            dados.append('action', 'delete');
            dados.append('id', id);

            axios.post('posts_controller.php', dados)
                .then(() => carregarPosts())
                .catch(err => alert(err?.response?.data?.error || 'Erro ao excluir.'));
        }

        document.addEventListener('DOMContentLoaded', () => {
            carregarPosts();

            axios.get('posts_controller.php?action=categorias')
                .then(res => {
                    const catSelect = document.getElementById('id_categoria');
                    catSelect.innerHTML = '';
                    res.data.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.text  = c.nome;
                        catSelect.add(opt);
                    });
                })
                .catch(err => alert(err?.response?.data?.error || 'Erro ao carregar categorias.'));
        });
    </script>
</body>
</html>
