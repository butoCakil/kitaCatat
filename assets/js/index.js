
const ctx = document.getElementById("barChart").getContext("2d");
new Chart(ctx, {
    type: "bar",
    data: {
        labels: window.CHART_LABELS,
        datasets: [
            {
                label: "Pemasukan",
                data: window.CHART_INCOME,
                backgroundColor: "rgba(22,163,74,.85)",
                borderRadius: 6,
                borderSkipped: false,
            },
            {
                label: "Pengeluaran",
                data: window.CHART_EXPENSE,
                backgroundColor: "rgba(220,38,38,.75)",
                borderRadius: 6,
                borderSkipped: false,
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: "top", labels: { font: { size: 12 }, boxWidth: 12 } },
            tooltip: {
                callbacks: {
                    label: ctx => " Rp " + ctx.raw.toLocaleString("id-ID")
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: v => "Rp " + (v/1000000).toFixed(1) + "jt",
                    font: { size: 11 }
                },
                grid: { color: "#f1f5f9" }
            },
            x: { grid: { display: false }, ticks: { font: { size: 12 } } }
        }
    }
});