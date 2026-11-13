var InventoryPage = {
  init: function () {
    var items = [],
      wh = [];
    function setup() {
      InventoryApp.loadItems(function (x) {
        items = x || [];
        var sel = document.getElementById("reportItem");
        if (sel)
          sel.innerHTML =
            '<option value="">All Items</option>' +
            items
              .map(function (i) {
                return (
                  '<option value="' +
                  i.id +
                  '">' +
                  InventoryApp.esc(
                    i.item_name + (i.size ? " - " + i.size : ""),
                  ) +
                  "</option>"
                );
              })
              .join("");
      });
      InventoryApp.loadWarehouses(function (x) {
        wh = x || [];
        var sel = document.getElementById("reportWarehouse");
        if (sel)
          sel.innerHTML =
            '<option value="">All Warehouses</option>' +
            wh
              .map(function (o) {
                return (
                  '<option value="' +
                  o.id +
                  '">' +
                  InventoryApp.esc(o.warehouse_name) +
                  "</option>"
                );
              })
              .join("");
      });
    }
    function renderSummary(summary) {
      var sumEl = document.getElementById("reportSummary");
      if (!sumEl) return;
      if (!summary) {
        sumEl.style.display = "none";
        sumEl.innerHTML = "";
        return;
      }
      sumEl.style.display = "grid";
      var cards = [
        ["Beginning Stock", InventoryApp.qty(summary.total_beginning_stock)],
        ["Total Stock In", InventoryApp.qty(summary.total_stock_in)],
        ["Total Stock Out", InventoryApp.qty(summary.total_stock_out)],
        ["Ending Stock", InventoryApp.qty(summary.total_ending_stock)],
        ["Low Stock Items", summary.low_stock_items],
      ];
      sumEl.innerHTML = cards
        .map(function (c) {
          return (
            '<div class="summary-card"><span>' +
            c[0] +
            "</span><b>" +
            c[1] +
            "</b></div>"
          );
        })
        .join("");
    }
    function run() {
      var data = {
        report_type: document.getElementById("reportType").value,
        date_from: document.getElementById("reportFrom").value,
        date_to: document.getElementById("reportTo").value,
        item_id: document.getElementById("reportItem").value,
        item_type: document.getElementById("reportTypeItem").value,
        warehouse_id: document.getElementById("reportWarehouse").value,
        movement_type: document.getElementById("reportMovement").value,
      };
      if (data.date_from && data.date_to && data.date_from > data.date_to) {
        InventoryApp.toast("From date cannot be later than To date");
        return;
      }
      InventoryApp.request("php/reports.php", data, "GET", function (r) {
        if (!r || !r.success) {
          InventoryApp.toast(r.message || "Unable to generate report");
          return;
        }
        document.getElementById("reportTitle").innerHTML =
          "<h3>" + InventoryApp.esc(r.title || "Inventory Report") + "</h3>";
        renderSummary(r.summary);
        document.getElementById("reportHead").innerHTML =
          "<tr>" +
          (r.columns || [])
            .map(function (c) {
              return "<th>" + InventoryApp.esc(c.label) + "</th>";
            })
            .join("") +
          "</tr>";
        document.getElementById("reportBody").innerHTML = (r.rows || []).length
          ? (r.rows || [])
              .map(function (row) {
                return (
                  "<tr>" +
                  r.columns
                    .map(function (c) {
                      return (
                        "<td>" + InventoryApp.esc(row[c.key] ?? "") + "</td>"
                      );
                    })
                    .join("") +
                  "</tr>"
                );
              })
              .join("")
          : '<tr><td colspan="' +
            r.columns.length +
            '" class="empty">No records found.</td></tr>';
        window.lastReport = r;
        var panel = document.querySelector(".report-panel");
        if (panel) panel.style.display = "block";
      });
    }
    function setDates() {
      var from = document.getElementById("reportFrom"),
        to = document.getElementById("reportTo");
      if (!from || !to) return;
      var d = new Date(),
        first = new Date(d.getFullYear(), d.getMonth(), 1);
      function fmt(x) {
        return (
          x.getFullYear() +
          "-" +
          String(x.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(x.getDate()).padStart(2, "0")
        );
      }
      if (!from.value) from.value = fmt(first);
      if (!to.value) to.value = fmt(d);
    }
    setup();
    setDates();
    var panel = document.querySelector(".report-panel");
    if (panel) panel.style.display = "none";
    var runBtn = document.getElementById("runReport");
    if (runBtn) runBtn.onclick = run;
    var printBtn = document.getElementById("printReport");
    if (printBtn)
      printBtn.onclick = function () {
        if (!window.lastReport) {
          InventoryApp.toast("Run Report first.");
          return;
        }
        window.print();
      };
  },
};
