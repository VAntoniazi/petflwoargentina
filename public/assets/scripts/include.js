function includeHTML(id, url) {
    fetch(url)
        .then(response => {
            if (!response.ok) throw new Error('Erro ao carregar ' + url);
            return response.text();
        })
        .then(data => {
            document.getElementById(id).innerHTML = data;
        })
        .catch(error => console.error(error));
}

document.addEventListener("DOMContentLoaded", function () {
    includeHTML("header-placeholder", "header.html");
    includeHTML("footer-placeholder", "footer.html");
});
