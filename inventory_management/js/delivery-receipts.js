var DeliveryReceiptPage = {
  init: function () {
    var rows = [];
    function load() {
      InventoryApp.request(
        "php/delivery-receipts.php",
        { action: "list" },
        "GET",
        function (r) {
          if (r.success) {
            rows = r.rows || [];
            render();
          } else {
            InventoryApp.toast(
              r.message || "Unable to load Delivery Receipts.",
            );
          }
        },
      );
    }
    function render() {
      var q = (document.getElementById("drSearch").value || "").toLowerCase();
      var data = rows.filter(function (x) {
        return JSON.stringify(x).toLowerCase().indexOf(q) >= 0;
      });
      document.getElementById("drTable").innerHTML =
        data
          .map(function (x) {
            return (
              '<tr><td><button class="action-btn dr-open" data-id="' +
              x.id +
              '">' +
              InventoryApp.esc(x.dr_number) +
              "</button></td><td>" +
              InventoryApp.esc(x.receipt_date || "") +
              "</td><td>" +
              InventoryApp.esc(x.request_number || "") +
              "</td><td>" +
              InventoryApp.esc(x.destination || "") +
              "</td><td>" +
              InventoryApp.esc(x.recipient_name || "") +
              "</td><td>" +
              InventoryApp.esc(x.item_name || "") +
              "</td><td>" +
              InventoryApp.esc(
                x.item_type === "Raw Material"
                  ? "----"
                  : x.item_size || x.size || "----",
              ) +
              "</td><td>" +
              InventoryApp.esc(x.requester || "") +
              "</td><td>" +
              InventoryApp.esc(x.received_by || "—") +
              '</td><td><span class="status ' +
              (x.status === "Delivered" || x.status === "Accepted"
                ? "active"
                : x.status === "Partially Delivered"
                  ? "partial"
                  : "low") +
              '">' +
              InventoryApp.esc(x.status || "") +
              '</span></td><td><button class="action-btn dr-open" data-id="' +
              x.id +
              '">View Details</button></td></tr>'
            );
          })
          .join("") ||
        '<tr><td colspan="11" class="empty">No Delivery Receipts found.</td></tr>';
    }
    function show(id) {
      InventoryApp.request(
        "php/delivery-receipts.php",
        { action: "details", id: id },
        "GET",
        function (r) {
          if (!r.success) {
            InventoryApp.toast(r.message);
            return;
          }
          var d = r.receipt || {},
            items = d.items || [];
          document.getElementById("drDetailContent").innerHTML =
            '<div class="form-grid"><label>DR Number<input readonly value="' +
            InventoryApp.esc(d.dr_number || "") +
            '"></label><label>Receipt Date<input readonly value="' +
            InventoryApp.esc(d.receipt_date || "") +
            '"></label><label>Request Number<input readonly value="' +
            InventoryApp.esc(d.request_number || "") +
            '"></label><label>Request Date<input readonly value="' +
            InventoryApp.esc(d.request_date || "") +
            '"></label><label>Destination<input readonly value="' +
            InventoryApp.esc(d.destination || "") +
            '"></label><label>Recipient<input readonly value="' +
            InventoryApp.esc(d.recipient_name || "") +
            '"></label><label>Requested By<input readonly value="' +
            InventoryApp.esc(d.requester || "") +
            '"></label><label>Received By<input readonly value="' +
            InventoryApp.esc(d.received_by || "") +
            '"></label><label>Status<input readonly value="' +
            InventoryApp.esc(d.status || "") +
            '"></label><label>Expected Date<input readonly value="' +
            InventoryApp.esc(d.expected_date || "") +
            '"></label></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Size</th><th>Warehouse</th><th>Location</th><th>Quantity</th><th>Unit</th></tr></thead><tbody>' +
            items
              .map(function (i) {
                return (
                  "<tr><td>" +
                  InventoryApp.esc(i.item_name) +
                  "</td><td>" +
                  InventoryApp.esc(
                    i.item_type === "Raw Material"
                      ? "----"
                      : i.item_size || i.size || "----",
                  ) +
                  "</td><td>" +
                  InventoryApp.esc(i.warehouse_name || "") +
                  "</td><td>" +
                  InventoryApp.esc(i.location || "") +
                  "</td><td>" +
                  InventoryApp.qty(i.quantity) +
                  "</td><td>" +
                  InventoryApp.esc(i.unit || "") +
                  "</td></tr>"
                );
              })
              .join("") +
            "</tbody></table></div><p><b>Remarks:</b> " +
            InventoryApp.esc(d.request_remarks || d.remarks || "") +
            "</p>";
          InventoryApp.openModal("drModal");
        },
      );
    }
    document.getElementById("drSearch").oninput = render;
    document.getElementById("drTable").onclick = function (e) {
      var b = e.target.closest(".dr-open");
      if (b) show(b.dataset.id);
    };
    load();
  },
};
document.addEventListener("DOMContentLoaded", function () {
  DeliveryReceiptPage.init();
});
