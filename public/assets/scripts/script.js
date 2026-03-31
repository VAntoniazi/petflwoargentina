// Contador regressivo para o lançamento
function startCountdown() {
    const launchDate = new Date("2025-04-01T00:00:00").getTime();
    const countdownElement = document.getElementById("countdown");

    setInterval(() => {
        const now = new Date().getTime();
        const diff = launchDate - now;

        if (diff > 0) {
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

            countdownElement.innerHTML = `${days}d ${hours}h ${minutes}m`;
        } else {
            countdownElement.innerHTML = "Lançamento disponível!";
        }
    }, 1000);
}

startCountdown();

// Envio de formulário
document.getElementById("leadForm").addEventListener("submit", async function(event) {
    event.preventDefault();

    let formData = new FormData(this);

    let response = await fetch("save_lead.php", {
        method: "POST",
        body: formData
    });

    let result = await response.json();
    document.getElementById("mensagem").textContent = result.message;

    if (result.success) {
        this.reset();
    }
});
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