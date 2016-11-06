<?php $this->layout('layout', [
                      'title' => $title,
                    ]); ?>

<div class="single-column">

  <section class="content">
    <h2>Testing your Subscriber</h2>

    <ul>
      <li><a href="/subscriber/100">100</a> - HTTP header discovery</li>
      <li><a href="/subscriber/101">101</a> - XML tag discovery</li>
      <li><a href="/subscriber/102">102</a> - HTML tag discovery</li>
      <li><a href="/subscriber/103">103</a> - Test unsubscribing</li>
    </ul>

    <h3>Error Handling</h3>
    <ul>
      <li><a href="/subscriber/200">200</a> - Reject invalid topic URLs on subscription validation</li>
      <li><a href="/subscriber/201">201</a> - Reject invalid signatures for authenticated distribution</li>
      <li><a href="/subscriber/202">202</a> - Reject missing signature for authenticated distribution</li>
    </ul>


  </section>

</div>
