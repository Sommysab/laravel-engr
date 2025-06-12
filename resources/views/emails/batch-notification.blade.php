<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claims Batch Notification</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background-color: #2563eb; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; }
        .batch-summary { background-color: #f8fafc; border-radius: 6px; padding: 20px; margin: 20px 0; }
        .stat { display: inline-block; margin: 10px 20px 10px 0; }
        .stat-value { font-size: 24px; font-weight: bold; color: #2563eb; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; }
        .claims-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .claims-table th, .claims-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .claims-table th { background-color: #f1f5f9; font-weight: 600; }
        .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 14px; color: #64748b; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Claims Batch Ready</h1>
            <p>Batch #{{ $batch->id }} - {{ $batch->provider_name }}</p>
        </div>
        
        <div class="content">
            <h2>Hello {{ $insurer->name }},</h2>
            
            <p>A new batch of healthcare claims is ready for processing. This batch has been optimally configured to minimize your processing costs while maintaining efficiency.</p>
            
            <div class="batch-summary">
                <h3>Batch Summary</h3>
                
                <div class="stat">
                    <div class="stat-value">{{ $batch->total_claims }}</div>
                    <div class="stat-label">Total Claims</div>
                </div>
                
                <div class="stat">
                    <div class="stat-value">${{ number_format($batch->total_amount, 2) }}</div>
                    <div class="stat-label">Total Amount</div>
                </div>
                
                <div class="stat">
                    <div class="stat-value">${{ number_format($batch->processing_cost, 2) }}</div>
                    <div class="stat-label">Processing Cost</div>
                </div>
                
                <div class="stat">
                    <div class="stat-value">{{ $batch->batch_date->format('M j, Y') }}</div>
                    <div class="stat-label">Batch Date</div>
                </div>
            </div>
            
            <h3>Claims Details</h3>
            <table class="claims-table">
                <thead>
                    <tr>
                        <th>Claim ID</th>
                        <th>Specialty</th>
                        <th>Priority</th>
                        <th>Amount</th>
                        <th>Items</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($claims as $claim)
                    <tr>
                        <td>#{{ $claim->id }}</td>
                        <td>{{ $claim->specialty }}</td>
                        <td>{{ $claim->priority_level }}/5</td>
                        <td>${{ number_format($claim->total_amount, 2) }}</td>
                        <td>{{ $claim->items->count() }} item(s)</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            
            <h3>Cost Optimization</h3>
            <p>This batch achieves an optimality score of <strong>{{ number_format($batch->getOptimalityScore() * 100, 1) }}%</strong>, ensuring maximum cost efficiency for your processing workflow.</p>
            
            <p>
                <a href="#" class="btn">View Batch Details</a>
                <a href="#" class="btn" style="background-color: #059669;">Process Batch</a>
            </p>
        </div>
        
        <div class="footer">
            <p>Healthcare Claims Processing System</p>
            <p>This batch was automatically optimized to minimize processing costs and maximize efficiency.</p>
            <p>If you have any questions, please contact your healthcare provider representative.</p>
        </div>
    </div>
</body>
</html> 