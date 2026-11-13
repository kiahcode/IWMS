var RejectionPage = {
  init: function () {
    var rows = [],
      current = null,
      pendingEmail = "",
      pendingEmailId = null;
    var $ = function (id) {
      return document.getElementById(id);
    };
    function saveSelectedId(id) {
      try {
        localStorage.setItem("inventory_last_rejection_id", String(id));
      } catch (e) {}
      try {
        sessionStorage.setItem("inventory_last_rejection_id", String(id));
      } catch (e) {}
    }
    function getSavedSelectedId() {
      try {
        return (
          localStorage.getItem("inventory_last_rejection_id") ||
          sessionStorage.getItem("inventory_last_rejection_id")
        );
      } catch (e) {
        return null;
      }
    }
    function load() {
      InventoryApp.request(
        "php/rejections.php",
        { action: "list" },
        "GET",
        function (r) {
          if (!r.success) {
            InventoryApp.toast(
              r.message || "Unable to load rejection reports.",
            );
            return;
          }
          rows = r.rows || [];
          render();
          var savedId = getSavedSelectedId();
          var targetId = current
            ? current.id
            : savedId &&
                rows.some(function (x) {
                  return String(x.id) === String(savedId);
                })
              ? savedId
              : rows[0]
                ? rows[0].id
                : null;
          if (targetId) show(targetId);
          else {
            current = null;
            $("rejectionDetailBody").hidden = true;
            $("rejectionDetailEmpty").hidden = false;
            $("sendEmailBtn").disabled = true;
          }
        },
      );
    }
    function render() {
      var q = ($("rejectionSearch").value || "").toLowerCase();
      var data = rows.filter(function (x) {
        return JSON.stringify(x).toLowerCase().indexOf(q) !== -1;
      });
      $("rejectionTable").innerHTML =
        data
          .map(function (x) {
            var supplier = (x.supplier_name || "").trim(),
              department = (x.department || "").trim();
            var supplierLabel =
              supplier &&
              department &&
              supplier.toLowerCase() !== department.toLowerCase()
                ? supplier
                : department || supplier || "—";
            var departmentLine =
              supplier &&
              department &&
              supplier.toLowerCase() !== department.toLowerCase()
                ? '<small class="rejection-department">' +
                  InventoryApp.esc(department) +
                  "</small>"
                : "";
            return (
              '<tr class="' +
              (current && Number(current.id) === Number(x.id)
                ? "selected"
                : "") +
              '"><td><b>' +
              InventoryApp.esc(
                x.report_number ||
                  "RR-" +
                    new Date().getFullYear() +
                    "-" +
                    String(x.id).padStart(3, "0"),
              ) +
              "</b>" +
              (String(x.report_number || "").indexOf("DEMO") !== -1
                ? '<span class="rejection-demo-note">SAMPLE</span>'
                : "") +
              "</td><td>" +
              InventoryApp.esc(x.po_number || "—") +
              '</td><td><span class="rejection-party-name">' +
              InventoryApp.esc(supplierLabel) +
              "</span>" +
              departmentLine +
              "</td><td>" +
              InventoryApp.esc(x.dr_number || "—") +
              "</td><td>" +
              InventoryApp.esc(x.received_date || "—") +
              '</td><td><button class="action-btn primary-action" data-view="' +
              x.id +
              '">◉ &nbsp; View</button></td></tr>'
            );
          })
          .join("") ||
        '<tr><td colspan="6" class="empty">No rejected items found. Rejected quantities recorded in Delivery Progress will automatically appear here.</td></tr>';
    }
    function makeEmailText(d) {
      var out = [
        "Good day,",
        "",
        "Please see the attached Rejection Report for the items listed below.",
        "",
        "Report ID: " + (d.report_number || "—"),
        "PO Number: " + (d.po_number || "—"),
        "Delivery Receipt: " + (d.dr_number || "—"),
        "",
        "Kindly review the rejected items and provide your response.",
      ];
      return out.join("\n");
    }
    function show(id) {
      InventoryApp.request(
        "php/rejections.php",
        { action: "details", id: id },
        "GET",
        function (r) {
          if (!r.success) {
            try {
              localStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            try {
              sessionStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            InventoryApp.toast(r.message || "Unable to open report.");
            return;
          }
          current = r.rejection;
          var d = current;
          saveSelectedId(d.id);
          var itemRows = (d.items || [])
            .map(function (i) {
              return (
                "<tr><td>" +
                InventoryApp.esc(i.item_name) +
                "</td><td>" +
                InventoryApp.qty(i.ordered_quantity) +
                " " +
                InventoryApp.esc(i.unit) +
                "</td><td>" +
                InventoryApp.qty(i.accepted_quantity) +
                " " +
                InventoryApp.esc(i.unit) +
                '</td><td><b class="rejected-qty">' +
                InventoryApp.qty(i.rejected_quantity) +
                " " +
                InventoryApp.esc(i.unit) +
                "</b></td><td>" +
                InventoryApp.esc(i.rejection_reason || "—") +
                "</td></tr>"
              );
            })
            .join("");
          var supplierName = (d.supplier_name || "").trim(),
            departmentName = (d.department || "").trim();
          var partyLabel =
            supplierName &&
            departmentName &&
            supplierName.toLowerCase() !== departmentName.toLowerCase()
              ? supplierName
              : departmentName || supplierName || "—";
          var partyTitle =
            supplierName &&
            departmentName &&
            supplierName.toLowerCase() !== departmentName.toLowerCase()
              ? "Supplier:"
              : "Department:";
          var body =
            '<div class="rejection-detail-main"><section class="rejection-subpanel"><h3>▣ &nbsp; Delivery Receipt Details</h3><div class="delivery-meta-grid">' +
            '<div class="delivery-meta-column"><div class="delivery-meta-pair"><b>DR Number:</b><span>' +
            InventoryApp.esc(d.dr_number || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Receipt Date:</b><span>' +
            InventoryApp.esc(d.receipt_date || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Received By:</b><span>' +
            InventoryApp.esc(d.received_by || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Date Received:</b><span>' +
            InventoryApp.esc(d.dr_received_date || d.received_date || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Delivery Status:</b><span>' +
            InventoryApp.esc(d.delivery_status || "—") +
            "</span></div></div>" +
            '<div class="delivery-meta-column"><div class="delivery-meta-pair"><b>Request Number:</b><span>' +
            InventoryApp.esc(d.request_number || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Request Date:</b><span>' +
            InventoryApp.esc(d.request_date || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Destination:</b><span>' +
            InventoryApp.esc(d.destination || d.department || "—") +
            '</span></div><div class="delivery-meta-pair"><b>Recipient:</b><span>' +
            InventoryApp.esc(d.recipient_name || partyLabel) +
            '</span></div><div class="delivery-meta-pair"><b>Expected Date:</b><span>' +
            InventoryApp.esc(d.expected_date || "—") +
            '</span></div></div></div><p class="muted" style="margin-top:12px">Linked record from the <a href="delivery-receipt.html?dr_id=' +
            encodeURIComponent(d.delivery_receipt_id || "") +
            '" target="_blank" rel="noopener">Delivery Receipt tab</a>.</p></section>' +
            '<section class="rejection-subpanel"><h3>▤ &nbsp; Rejection Report Information</h3><div class="delivery-meta-grid"><div class="delivery-meta-column"><div class="delivery-meta-pair"><b>Report ID:</b><span>' +
            InventoryApp.esc(d.report_number || "—") +
            '</span></div><div class="delivery-meta-pair"><b>PO Number:</b><span>' +
            InventoryApp.esc(d.po_number || "—") +
            '</span></div><div class="delivery-meta-pair"><b>' +
            partyTitle +
            "</b><span>" +
            InventoryApp.esc(partyLabel) +
            '</span></div></div><div class="delivery-meta-column"><div class="delivery-meta-pair"><b>Requested By:</b><span>' +
            InventoryApp.esc(d.requester || "Inventory / Warehouse Team") +
            "</span></div></div></div></section>" +
            '<section class="rejection-subpanel inspection-summary"><h3>▤ &nbsp; Inspection Summary</h3><div class="table-wrap"><table><thead><tr><th>Item Name</th><th>Ordered Qty</th><th>Accepted Qty</th><th>Rejected Qty</th><th>Reason</th></tr></thead><tbody>' +
            itemRows +
            "</tbody></table></div></section>" +
            "</div>";
          $("rejectionDetailBody").innerHTML = body;
          $("rejectionDetailBody").hidden = false;
          $("rejectionDetailEmpty").hidden = true;
          $("sendEmailBtn").disabled = false;
          $("sendEmailBtn").textContent = "➤  Send Email";
          pendingEmail = "";
          pendingEmailId = null;
          render();
        },
      );
    }
    function generatePdf(d) {
      // Build a small standards-compliant PDF locally; no CDN or internet dependency.
      var pageW = 595.28,
        pageH = 841.89,
        margin = 42,
        y = pageH - 48,
        pages = [],
        stream = [];
      function clean(v) {
        return String(v == null || v === "" ? "—" : v)
          .normalize("NFKD")
          .replace(/[\u0300-\u036f]/g, "")
          .replace(/[^\x20-\x7E]/g, "?");
      }
      function esc(v) {
        return clean(v)
          .replace(/\\/g, "\\\\")
          .replace(/\(/g, "\\(")
          .replace(/\)/g, "\\)");
      }
      function beginPage() {
        stream = [];
        pages.push(stream);
        y = pageH - 48;
      }
      function text(t, x, yy, size, bold, color) {
        var c = color || [0.14, 0.14, 0.14];
        stream.push(
          "BT /F" +
            (bold ? "2" : "1") +
            " " +
            size +
            " Tf " +
            c[0] +
            " " +
            c[1] +
            " " +
            c[2] +
            " rg " +
            x.toFixed(2) +
            " " +
            yy.toFixed(2) +
            " Td (" +
            esc(t) +
            ") Tj ET",
        );
      }
      function rule(x1, yy, x2) {
        stream.push(
          "0.82 0.82 0.82 RG 0.5 w " +
            x1 +
            " " +
            yy.toFixed(2) +
            " m " +
            x2 +
            " " +
            yy.toFixed(2) +
            " l S",
        );
      }
      function ensure(h) {
        if (y - h < 45) {
          beginPage();
        }
      }
      function wrap(t, max) {
        var words = clean(t).split(/\s+/),
          out = [],
          line = "";
        words.forEach(function (w) {
          var test = line ? line + " " + w : w;
          if (test.length > max && line) {
            out.push(line);
            line = w;
          } else line = test;
        });
        if (line) out.push(line);
        return out.length ? out : ["—"];
      }
      function heading(t) {
        ensure(30);
        y -= 5;
        text(t, margin, y, 11, true, [0.51, 0.06, 0.1]);
        y -= 8;
        rule(margin, y, pageW - margin);
        y -= 16;
      }
      function field(label, value) {
        var lines = wrap(label + ": " + (value || "—"), 92);
        ensure(lines.length * 13 + 3);
        lines.forEach(function (line) {
          text(line, margin, y, 9, false);
          y -= 13;
        });
      }
      beginPage();
      text("INVENTORY MANAGEMENT", margin, y, 18, true, [0.57, 0.08, 0.12]);
      y -= 27;
      text("REJECTION REPORT", margin, y, 13, true);
      y -= 25;
      heading("DELIVERY RECEIPT DETAILS");
      field("DR Number", d.dr_number);
      field("Receipt Date", d.receipt_date);
      field("Received By", d.received_by);
      field("Date Received", d.dr_received_date || d.received_date);
      field("Delivery Status", d.delivery_status);
      field("Request Number", d.request_number);
      field("Request Date", d.request_date);
      field("Destination", d.destination || d.department);
      field("Recipient", d.recipient_name);
      field("Expected Date", d.expected_date);
      heading("REJECTION REPORT INFORMATION");
      field("Report ID", d.report_number);
      field("PO Number", d.po_number);
      field("Supplier / Department", d.supplier_name || d.department);
      field("Requested By", d.requester);
      heading("INSPECTION SUMMARY");
      var headers = ["Item Name", "Ordered", "Accepted", "Rejected", "Reason"],
        xs = [margin, 225, 300, 375, 450],
        maxChars = [29, 10, 10, 10, 25];
      ensure(35);
      headers.forEach(function (h, i) {
        text(h, xs[i], y, 8, true);
      });
      y -= 8;
      rule(margin, y, pageW - margin);
      y -= 15;
      (d.items || []).forEach(function (item) {
        var vals = [
          item.item_name || "—",
          String(item.ordered_quantity || 0) + " " + (item.unit || ""),
          String(item.accepted_quantity || 0) + " " + (item.unit || ""),
          String(item.rejected_quantity || 0) + " " + (item.unit || ""),
          item.rejection_reason || "—",
        ];
        var cells = vals.map(function (v, i) {
            return wrap(v, maxChars[i]);
          }),
          rh =
            Math.max.apply(
              null,
              cells.map(function (a) {
                return a.length;
              }),
            ) *
              11 +
            7;
        ensure(rh);
        cells.forEach(function (lines, i) {
          lines.forEach(function (line, j) {
            text(line, xs[i], y - j * 11, 7.5, false);
          });
        });
        y -= rh;
        rule(margin, y + 4, pageW - margin);
        y -= 8;
      });
      ensure(25);
      y -= 8;
      text(
        "Generated from the Inventory Management System",
        margin,
        y,
        8,
        false,
        [0.4, 0.4, 0.4],
      );
      var objects = [],
        add = function (v) {
          objects.push(v);
          return objects.length;
        };
      var catalog = add("<< /Type /Catalog /Pages 2 0 R >>"),
        pagesObj = add(""),
        font1 = add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"),
        font2 = add(
          "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
        ),
        pageIds = [];
      pages.forEach(function (lines) {
        var content = lines.join("\n"),
          streamId = add(
            "<< /Length " +
              content.length +
              " >>\nstream\n" +
              content +
              "\nendstream",
          ),
          pageId = add(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " +
              pageW +
              " " +
              pageH +
              "] /Resources << /Font << /F1 " +
              font1 +
              " 0 R /F2 " +
              font2 +
              " 0 R >> >> /Contents " +
              streamId +
              " 0 R >>",
          );
        pageIds.push(pageId);
      });
      objects[pagesObj - 1] =
        "<< /Type /Pages /Kids [" +
        pageIds
          .map(function (id) {
            return id + " 0 R";
          })
          .join(" ") +
        "] /Count " +
        pageIds.length +
        " >>";
      var pdf = "%PDF-1.4\n",
        offsets = [0];
      objects.forEach(function (obj, i) {
        offsets.push(pdf.length);
        pdf += i + 1 + " 0 obj\n" + obj + "\nendobj\n";
      });
      var xref = pdf.length;
      pdf += "xref\n0 " + (objects.length + 1) + "\n0000000000 65535 f \n";
      for (var k = 1; k < offsets.length; k++)
        pdf += String(offsets[k]).padStart(10, "0") + " 00000 n \n";
      pdf +=
        "trailer\n<< /Size " +
        (objects.length + 1) +
        " /Root " +
        catalog +
        " 0 R >>\nstartxref\n" +
        xref +
        "\n%%EOF";
      var blob = new Blob([pdf], { type: "application/pdf" }),
        url = URL.createObjectURL(blob),
        a = document.createElement("a");
      a.href = url;
      a.download =
        (d.report_number || "Rejection_Report").replace(
          /[^A-Za-z0-9_-]/g,
          "_",
        ) + ".pdf";
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () {
        URL.revokeObjectURL(url);
      }, 30000);
      return true;
    }
    $("seedDemoRejections").onclick = function () {
      if (
        !confirm(
          "Add clearly labelled sample rejection reports for Procurement, Production and Sales? These are test records only.",
        )
      )
        return;
      InventoryApp.request(
        "php/rejections.php",
        { action: "seed_demo" },
        "POST",
        function (r) {
          InventoryApp.toast(r.message || "Sample data request completed.");
          if (r.success) {
            current = null;
            try {
              localStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            try {
              sessionStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            load();
          }
        },
      );
    };
    $("clearDemoRejections").onclick = function () {
      if (
        !confirm(
          "Remove only the sample rejection reports marked DEMO? Real rejection records will not be deleted.",
        )
      )
        return;
      InventoryApp.request(
        "php/rejections.php",
        { action: "clear_demo" },
        "POST",
        function (r) {
          InventoryApp.toast(r.message || "Sample data cleanup completed.");
          if (r.success) {
            current = null;
            try {
              localStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            try {
              sessionStorage.removeItem("inventory_last_rejection_id");
            } catch (e) {}
            load();
          }
        },
      );
    };
    $("rejectionTable").onclick = function (e) {
      var b = e.target.closest("[data-view]");
      if (b) show(b.dataset.view);
    };
    $("sendEmailBtn").onclick = function () {
      if (!current) return;
      if (pendingEmailId && Number(pendingEmailId) === Number(current.id)) {
        if (
          !confirm(
            "Have you attached the downloaded PDF and sent the email in Gmail?",
          )
        )
          return;
        InventoryApp.request(
          "php/rejections.php",
          {
            action: "email_sent",
            id: pendingEmailId,
            supplier_gmail: pendingEmail,
          },
          "POST",
          function (r) {
            InventoryApp.toast(r.message);
            if (r.success) {
              pendingEmail = "";
              pendingEmailId = null;
              $("sendEmailBtn").textContent = "➤  Send Email";
              show(current.id);
              load();
            }
          },
        );
        return;
      }
      var d = current,
        email = (d.supplier_gmail || "").trim();
      if (!email)
        email = window.prompt(
          "Enter the supplier or department email address:",
          "",
        );
      if (!email) return;
      email = email.trim();
      if (!/^\S+@\S+\.\S+$/.test(email)) {
        InventoryApp.toast("Please enter a valid email address.");
        return;
      }
      if (!generatePdf(d)) return;
      var subject =
          "Rejected Items Notice - " + (d.po_number || d.report_number),
        body = makeEmailText(d);
      var url =
        "https://mail.google.com/mail/?view=cm&fs=1&to=" +
        encodeURIComponent(email) +
        "&su=" +
        encodeURIComponent(subject) +
        "&body=" +
        encodeURIComponent(body);
      var gmailWindow = window.open(url, "_blank");
      pendingEmail = email;
      pendingEmailId = d.id;
      $("sendEmailBtn").textContent = "✓  Mark as Email Sent";
      InventoryApp.toast(
        gmailWindow
          ? "PDF downloaded and Gmail Compose opened. Drag the PDF into Gmail, send it, then return here and click Mark as Email Sent."
          : "PDF downloaded. Pop-up was blocked; open Gmail manually, attach the PDF, send it, then return here and click Mark as Email Sent.",
      );
    };
    $("rejectionSearch").oninput = render;
    $("refreshRejections").onclick = load;
    window.addEventListener("pageshow", function (e) {
      if (e.persisted) load();
    });
    InventoryApp.session(function (r) {
      if (!r.user.can_control) {
        InventoryApp.toast(
          "Warehouse Manager or Inventory Controller access required.",
        );
        return;
      }
      load();
    });
  },
};
document.addEventListener("DOMContentLoaded", function () {
  RejectionPage.init();
});
