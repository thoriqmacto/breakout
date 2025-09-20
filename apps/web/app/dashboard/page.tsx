"use client"

import { useAuth } from "@/components/auth-provider"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"

const highlights = [
  {
    title: "Total Strategies",
    value: "6",
    description: "Configured breakout strategies currently monitored.",
  },
  {
    title: "Open Alerts",
    value: "12",
    description: "Signals awaiting analyst review across all assets.",
  },
  {
    title: "Last Sync",
    value: "3m ago",
    description: "Time since the asset metrics pipeline completed.",
  },
]

export default function DashboardPage() {
  const { user } = useAuth()

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-semibold tracking-tight">Welcome back, {user?.name.split(" ")[0] ?? ""}</h1>
        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
          Use this dashboard to review analytics from the Breakout API and manage operational workflows across your
          trading strategies.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {highlights.map((item) => (
          <Card key={item.title}>
            <CardHeader>
              <CardTitle className="text-base font-semibold">{item.title}</CardTitle>
              <CardDescription>{item.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-3xl font-semibold">{item.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Recent activity</CardTitle>
          <CardDescription>
            Track pipeline health and user activity sourced from the authentication API.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 text-sm text-muted-foreground">
          <p>
            • Refreshed authentication token issued for <span className="font-medium">{user?.email}</span>.
          </p>
          <p>• Asset metrics synchronization completed without errors.</p>
          <p>• Queue workers are idle and standing by for new events.</p>
        </CardContent>
      </Card>
    </div>
  )
}
