// ==========================================
// CONFIGURATION SECTION
// ==========================================

// 1. YOUR LIVE WEBSITE URL
var targetUrl = "https://saprivateschools.co.za/wp-json/ngd/v1/payment_receiver";

// 2. YOUR SECRET KEY
// This matches the 'PaymentWebhook.php' file on your Live Server
var secretKey = "T9S%OK&vK9]qsWU5hpMIbbR9ZTl7"; 

// 3. EMAIL SETTINGS
var bankEmail = "incontact@fnb.co.za"; 
var subjectFilter = "paid to Current"; 

// ==========================================
// END CONFIGURATION
// ==========================================

function processFnbPayments() {
  // 1. SEARCH GMAIL
  // Searches for unread emails with specific subject (Sender ignored to allow forwarding)
  var threads = GmailApp.search('subject:"' + subjectFilter + '" is:unread');

  if (threads.length === 0) {
    console.log("No new payment emails found.");
    return;
  }

  // 2. SETUP LABEL (To mark processed threads)
  var labelName = "Processed_By_Script";
  var label = GmailApp.getUserLabelByName(labelName);
  if (!label) label = GmailApp.createLabel(labelName);

  for (var i = 0; i < threads.length; i++) {
    var messages = threads[i].getMessages();
    
    for (var j = 0; j < messages.length; j++) {
      var msg = messages[j];
      
      // Safety check: Skip if already read
      if (msg.isUnread() === false) continue;

      var subject = msg.getSubject();
      var body = msg.getPlainBody(); 
      // Optimization: Search both Subject and Body at once
      var fullText = subject + " " + body; 

      // 3. EXTRACT DATA
      
      // A. Amount (e.g. R 4,999.00)
      var amountMatch = fullText.match(/R\s?([\d,]+\.\d{2})/);
      var amount = amountMatch ? amountMatch[1].replace(/,/g, '') : "0.00";

      // B. Reference (Smart Logic)
      var reference = "";
      var is_fuzzy = false;

      // Priority 1: Strict SCH Code (e.g. SCH-2749-9921)
      var strictMatch = fullText.match(/(SCH-\d+-\d+)/i);

      if (strictMatch) {
          reference = strictMatch[1];
      } else {
          // Priority 2: Fuzzy Search
          // Captures text after "Ref" or "Reference" (e.g. "Ref: Rallim School")
          var fuzzyMatch = fullText.match(/Ref(?:erence)?[.:\s]+([A-Za-z0-9\s-]+)/i);
          if (fuzzyMatch) {
             reference = fuzzyMatch[1].trim();
             is_fuzzy = true; // Flags this for Manual Approval in WordPress
          }
      }

      // 4. SEND TO WORDPRESS
      if (reference) {
        sendWebhook(reference, amount, is_fuzzy);
        msg.markRead(); // Mark as read immediately so we don't process it twice
      }
    }
    // Label the thread as processed
    threads[i].addLabel(label);
  }
}

function sendWebhook(ref, amt, is_fuzzy) {
  var payload = {
    "amount": amt,
    "reference": ref,
    "secret": secretKey,
    "is_fuzzy": is_fuzzy
  };

  var options = {
    "method": "post",
    "contentType": "application/json",
    "payload": JSON.stringify(payload),
    "muteHttpExceptions": true
  };

  try {
    var response = UrlFetchApp.fetch(targetUrl, options);
    console.log("Sent: " + ref + " | Response: " + response.getContentText());
  } catch (e) {
    console.log("Error sending webhook: " + e.toString());
  }
}

// TEST TOOL: Run this manually to verify connections
function testConnection() {
  console.log("Testing connection to: " + targetUrl);
  // Send a dummy fuzzy match to test the system
  sendWebhook("TEST-CONNECTION-CHECK", "100.00", true); 
}