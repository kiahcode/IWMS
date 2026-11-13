var InventoryPage = {
  init: function () {
    InventoryApp.request("php/dashboard.php", {}, "GET", function (r) {
      if (!r.success) return;
      [
        "totalItems",
        "rawMaterials",
        "finishedGoods",
        "totalStock",
        "totalWarehouses",
        "stockInToday",
        "stockOutToday",
        "lowStockItems",
      ].forEach(function (id) {
        document.getElementById(id).textContent = InventoryApp.qty(r.stats[id]);
      });
      var ov = document.getElementById("inventoryOverview"),
        max = 1;
      r.overview.forEach(function (x) {
        max = Math.max(max, Number(x.total_stock));
      });
      ov.innerHTML = r.overview.length
        ? r.overview
            .map(function (x) {
              return (
                '<div class="bar-row"><span>' +
                InventoryApp.esc(x.item_name) +
                '</span><div class="bar"><i style="width:' +
                Math.max(3, (Number(x.total_stock) / max) * 100) +
                '%"></i></div><b>' +
                InventoryApp.qty(x.total_stock) +
                "</b></div>"
              );
            })
            .join("")
        : '<div class="empty">No inventory yet.</div>';
      var low = document.getElementById("dashboardLowStock");
      low.innerHTML = r.low_stock.length
        ? r.low_stock
            .map(function (x) {
              return (
                '<div class="compact-item"><span><b>' +
                InventoryApp.esc(x.item_code) +
                "</b><br><small>" +
                InventoryApp.esc(x.item_name) +
                " · " +
                InventoryApp.esc(x.warehouse_name) +
                '</small></span><b class="' +
                (x.status === "Critical" ? "error" : "") +
                '">' +
                InventoryApp.qty(x.quantity) +
                "</b></div>"
              );
            })
            .join("")
        : '<div class="empty">No low stock items.</div>';
      var mv = document.getElementById("recentMovements");
      mv.innerHTML = r.movements.length
        ? r.movements
            .map(function (x) {
              return (
                "<tr><td>" +
                InventoryApp.esc(x.date) +
                "</td><td>" +
                InventoryApp.esc(x.item_name) +
                "</td><td>" +
                InventoryApp.esc(x.movement_type) +
                "</td><td>" +
                InventoryApp.qty(x.quantity) +
                "</td><td>" +
                InventoryApp.esc(x.warehouse_name) +
                "</td></tr>"
              );
            })
            .join("")
        : '<tr><td colspan="5" class="empty">No movements.</td></tr>';
      var tx = document.getElementById("recentTransactions");
      tx.innerHTML = r.transactions.length
        ? r.transactions
            .map(function (x) {
              return (
                '<div class="compact-item"><span><b>' +
                InventoryApp.esc(x.type) +
                "</b><br><small>" +
                InventoryApp.esc(x.number) +
                " · " +
                InventoryApp.esc(x.item_name) +
                "</small></span><b>" +
                InventoryApp.qty(x.quantity) +
                "</b></div>"
              );
            })
            .join("")
        : '<div class="empty">No transactions.</div>';
    });
  },
};
